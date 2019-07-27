<?php

namespace Biopen\GeoDirectoryBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Biopen\GeoDirectoryBundle\Document\Element;
use Biopen\GeoDirectoryBundle\Document\ElementStatus;
use Biopen\GeoDirectoryBundle\Document\UserInteractionContribution;
use Biopen\GeoDirectoryBundle\Document\ImportState;
use Biopen\CoreBundle\Document\GoGoLogImport;
use Biopen\GeoDirectoryBundle\Document\ModerationState;

class ElementImportService
{
	private $em;

	protected $countElementCreated = 0;
	protected $countElementUpdated = 0;
	protected $countElementNothingToDo = 0;
	protected $countElementErrors = 0;
	protected $elementIdsErrors = [];
	protected $errorsMessages = [];
	protected $errorsCount = [];

	/**
    * Constructor
    */
  public function __construct(DocumentManager $documentManager, $importOneService, $mappingService)
  {
		$this->em = $documentManager;
		$this->importOneService = $importOneService;
		$this->mappingService = $mappingService;
  }

  public function startImport($import)
  {
		$this->countElementCreated = 0;
		$this->countElementUpdated = 0;
		$this->countElementNothingToDo = 0;
		$this->countElementErrors = 0;
		$this->elementIdsErrors = [];
		$this->errorsMessages = [];
		$this->errorsCount = [];

  	$import->setCurrState(ImportState::Downloading);
  	$import->setCurrMessage("Téléchargement des données en cours... Veuillez patienter...");
  	$this->em->persist($import);
  	$this->em->flush();
  	if ($import->getUrl()) return $this->importJson($import);
  	else return $this->importCsv($import);
  }

  public function importCsv($import, $onlyGetData = false)
  {
  	$fileName = $import->getFilePath();

		// Getting php array of data from CSV
		$header = NULL;
		$delimiter = ',';
    $data = array();

    if (($handle = fopen($fileName, 'r')) !== FALSE) {
      while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        if(!$header) {
          $header = $row;
        } else {
          if (count($header) != count ($row)) dump($row);
          else $data[] = array_combine($header, $row);
        }
      }
      fclose($handle);
    }

		if (!$data) {
			$import->setCurrMessage("Impossible d'ouvrir le fichier CSV. Vérifiez que le fichier utilise des virgules comme séparateur");
			$this->em->flush();
			return "Cannot open the CSV file";
		}

		if ($onlyGetData) return $data;

		return $this->importData($data, $import);
  }


  public function importJson($import, $onlyGetData = false)
  {
  	$json = file_get_contents($import->getUrl());
    $data = json_decode($json, true);
    if ($data === null) return null;

    // data can be stored inside a data attribute
    if (array_key_exists('data', $data)) $data = $data['data'];

    foreach ($data as $key => $row) {
			if (array_key_exists('geo', $row))
			{
				$data[$key]['latitude']  = $row['geo']['latitude'];
				$data[$key]['longitude'] = $row['geo']['longitude'];
				unset($data[$key]['geo']);
			}
			if (array_key_exists('address', $row))
			{
				$address = $row['address'];

				if (gettype($address) == "string") $data[$key]['streetAddress'] = $address;
				else if ($address) {
					if (array_key_exists('streetAddress', $address))   $data[$key]['streetAddress']   = $address['streetAddress'];
					if (array_key_exists('addressLocality', $address)) $data[$key]['addressLocality'] = $address['addressLocality'];
					if (array_key_exists('postalCode', $address))      $data[$key]['postalCode']      = $address['postalCode'];
					if (array_key_exists('addressCountry', $address))  $data[$key]['addressCountry']  = $address['addressCountry'];
				}
				unset($data[$key]['address']);
			}
		}

    if ($onlyGetData) return $data;

    $elementImportedCount = $this->importData($data, $import);

    return $elementImportedCount;
  }

  // read the data and extract ontology and categories. After this operation, the user will be able
  // create a mapping table for ontology and taxonomy
  public function collectData($import)
  {
		$data = $import->getUrl() ?$this->importJson($import, true) : $this->importCsv($import, true);
  	$this->mappingService->collectData($data, $import);
  	$this->em->persist($import);
  	$this->em->flush();
  }

	public function importData($data, $import)
	{
		if (!$data) return 0;
		// Define the size of record, the frequency for persisting the data and the current index of records
		$size = count($data); $batchSize = 100; $i = 0;

		// still collect data on each import because the list of fields and categories might change
		$this->mappingService->collectData($data, $import);
		// do the mapping
		$data = $this->mappingService->transform($data, $import);
    // remove empty row, i.e. without name
    $data = array_filter($data, function($row) { return $row['name']; });

		if ($import->isDynamicImport())
		{
			$import->setLastRefresh(time());
	    $import->updateNextRefreshDate();
		}

		if ($import->isDynamicImport())
		{
			// before updating the source, we put all elements into DynamicImportTemp status
			$qb = $this->em->createQueryBuilder('BiopenGeoDirectoryBundle:Element');
			$qb->updateMany()
				 ->field('source')->references($import)
				 ->field('status')->gt(ElementStatus::Deleted) // leave the deleted one as they are, so we know we do not need to import them
	       ->field('status')->set(ElementStatus::DynamicImportTemp)
	       ->getQuery()->execute();
	  }

	  $import->setCurrState(ImportState::InProgress);

	  $this->importOneService->initialize($import);

		// processing each data
		foreach($data as $row)
		{
			try {
				$import->setCurrMessage("Importation des données " . $i . '/' . $size . ' traitées');
				$this->importOneService->createElementFromArray($row, $import);
				$i++;
			}
			catch (\Exception $e) {
				$this->countElementErrors++;
				if (!is_array($row['id'])) $this->elementIdsErrors[] = "" . $row['id'];

				if (!array_key_exists($e->getMessage(), $this->errorsCount)) $this->errorsCount[$e->getMessage()] = 1;
				else $this->errorsCount[$e->getMessage()]++;
				$message = '<u>' . $e->getMessage() . '</u> <b>(x' . $this->errorsCount[$e->getMessage()] . ')</b></br>' . $e->getFile() . ' LINE ' . $e->getLine() . '</br>';
				$message .= 'CONTEXT : ' . print_r($row, true);
				$this->errorsMessages[$e->getMessage()] = $message;
			}

			if (($i % $batchSize) === 1)
			{
			   $this->em->flush();
			   $this->em->clear();
			   // After flush, we need to get again the import from the DB to avoid doctrine raising errors
			   $import = $this->em->getRepository('BiopenGeoDirectoryBundle:Import')->find($import->getId());
			   $this->em->persist($import);
			}
		}

		$this->em->flush();
		$this->em->clear();
		$import = $this->em->getRepository('BiopenGeoDirectoryBundle:Import')->find($import->getId());
		$this->em->persist($import);

		$countElemenDeleted = 0;
		if ($import->isDynamicImport())
    {
      if ($this->countElementErrors > 0)
      {
      	// If there was an error whil retrieving an already existing element
      	// we set back the status to DynamicImport otherwise it will be deleted just after
	      $qb = $this->em->createQueryBuilder('BiopenGeoDirectoryBundle:Element');
	      $result = $qb->updateMany()
	         ->field('source')->references($import)->field('oldId')->in($this->elementIdsErrors)
	         ->field('status')->set(ElementStatus::DynamicImport)
	         ->getQuery()->execute();
      }

      // after updating the source, the element still in DynamicImportTemp are the one who are missing
      // from the new data received, so we need to delete them
      $qb = $this->em->createQueryBuilder('BiopenGeoDirectoryBundle:Element');
      $deleteQuery = $qb
         ->field('source')->references($import)
         ->field('status')->equals(ElementStatus::DynamicImportTemp);

      $deletedElementIds = array_keys($deleteQuery->select('id')->hydrate(false)->getQuery()->execute()->toArray());
      $qb = $this->em->createQueryBuilder(UserInteractionContribution::class);
      $qb->field('element.id')->in($deletedElementIds)->remove()->getQuery()->execute();

      $countElemenDeleted = $deleteQuery->remove()->getQuery()->execute()['n'];
    }

		$qb = $this->em->createQueryBuilder('BiopenGeoDirectoryBundle:Element');
		$totalCount = $qb->field('status')->field('source')->references($import)->count()->getQuery()->execute();

		$qb = $this->em->createQueryBuilder('BiopenGeoDirectoryBundle:Element');
		$elementsMissingGeoCount = $qb->field('source')->references($import)->field('moderationState')->equals(ModerationState::GeolocError)->count()->getQuery()->execute();
		$qb = $this->em->createQueryBuilder('BiopenGeoDirectoryBundle:Element');
		$elementsMissingTaxoCount = $qb->field('source')->references($import)->field('moderationState')->equals(ModerationState::NoOptionProvided)->count()->getQuery()->execute();

		$logData = [
			"elementsCount" => $totalCount,
			"elementsCreatedCount" => $this->countElementCreated,
			"elementsUpdatedCount" => $this->countElementUpdated,
			"elementsNothingToDoCount" => $this->countElementNothingToDo,
			"elementsMissingGeoCount" => $elementsMissingGeoCount,
			"elementsMissingTaxoCount" => $elementsMissingTaxoCount,
			"elementsDeletedCount" => $countElemenDeleted,
			"elementsErrorsCount" => $this->countElementErrors,
			"errorMessages" => $this->errorsMessages
		];

		$totalErrors = $elementsMissingGeoCount + $elementsMissingTaxoCount + $this->countElementErrors;
		$logLevel = $totalErrors > 0 ? ($totalErrors > ($size / 4) ? 'error' : 'warning') : 'success';

		$message = "Import de " . $import->getSourceName() . " terminé";
		if ($logLevel != 'success') $message .= ", mais avec des problèmes !";

		$log = new GoGoLogImport($logLevel, $message, $logData);
		$import->addLog($log);

		$import->setCurrState($totalErrors > 0 ? ($totalErrors == $size ? ImportState::Failed : ImportState::Errors) : ImportState::Completed);
  	$import->setCurrMessage($log->displayMessage());

		$this->em->flush();

		return $message;
	}
}