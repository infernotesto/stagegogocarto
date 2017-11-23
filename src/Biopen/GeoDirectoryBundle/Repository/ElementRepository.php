<?php

/**
 * This file is part of the MonVoisinFaitDuBio project.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) 2016 Sebastian Castro - 90scastro@gmail.com
 * @license    MIT License
 * @Last Modified time: 2017-11-23 14:22:20
 */
 

namespace Biopen\GeoDirectoryBundle\Repository;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Biopen\GeoDirectoryBundle\Document\ElementStatus;
use Biopen\GeoDirectoryBundle\Document\ModerationState;

/**
 * ElementRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ElementRepository extends DocumentRepository
{
  // public function findAll()
  // {
  //   $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');
  //   return $qb->select('compactJson')->hydrate(false)->getQuery()->execute()->toArray(); 
  // }

  public function findDuplicatesAround($lat, $lng, $distance, $maxResults, $text)
  {
    $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');

    $expr = $qb->expr()->operator('$text', array('$search' => $text));
    // convert kilometre in degrees
    $radius = $distance / 110;
    return $qb  //->limit($maxResults)
                ->equals($expr->getQuery())
                ->field('geo')->withinCenter((float)$lat, (float)$lng, $radius)                
                ->sortMeta('score', 'textScore')
                ->hydrate(false)->getQuery()->execute()->toArray();
    
  }

  public function findWhithinBoxes($bounds, $optionId, $getFullRepresentation)
  {
    $results = [];

    $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');

    //dump("quering getFullRepresentation " . $getFullRepresentation);

    foreach ($bounds as $key => $bound) 
    {
      if (count($bound) == 4)
      {
        $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');

        $this->filterVisibles($qb);

        if ($optionId && $optionId != "all")
        {
          //$qb->where("function() { return this.optionValues.some(function(optionValue) { return optionValue.optionId == " . $optionId . "; }); }");
          $qb->field('optionValues.optionId')->in(array((float) $optionId));
        }

        // get elements within box
        $qb->field('geo')->withinBox((float) $bound[1], (float) $bound[0], (float) $bound[3], (float) $bound[2]);

        // get json representation
        if ($getFullRepresentation == 'true') 
        {
          $qb->select('fullJson'); 
        }
        else
        {
          $qb->select('compactJson');   
        } 

        // execute request   
        $array = $this->queryToArray($qb);
        $results = array_merge($results, $array);  
      }
    }

    return $results;
  }

  public function findElementsWithText($text)
  {
    $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');

    $expr = $qb->expr()->operator('$text', array('$search' => (string) $text));
    
    $qb  //->limit(50)
                ->equals($expr->getQuery())        
                ->sortMeta('score', 'textScore');
    
    $this->filterVisibles($qb);
                
    return $this->queryToArray($qb);
    
  }

  public function findPendings($getCount = false)
  {
    $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');

    $qb->field('status')->in(array(ElementStatus::PendingAdd,ElementStatus::PendingModification));
    if ($getCount) $qb->count();
    
    return $qb->getQuery()->execute();
  }

  public function findModerationNeeded($getCount = false)
  {
    $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');

    $qb->field('moderationState')->notEqual(ModerationState::NotNeeded);
    $qb->field('status')->gte(ElementStatus::PendingModification);
    if ($getCount) $qb->count();

    return $qb->getQuery()->execute();
  }

  public function findValidated($getCount = false)
  {
    $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');

    $qb->field('status')->gt(ElementStatus::PendingAdd);
    if ($getCount) $qb->count();

    return $qb->getQuery()->execute();
  }

  public function findVisibles($getCount = false)
  {
    $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');

    $qb = $this->filterVisibles($qb);
    
    if ($getCount) $qb->count();

    return $qb->getQuery()->execute();
  }

  public function findAllElements($limit = null, $skip = null, $getCount = false)
  {
    $qb = $this->createQueryBuilder('BiopenGeoDirectoryBundle:Element');
    
    if ($limit) $qb->limit($limit);
    if ($skip) $qb->skip($skip);
    if ($getCount) $qb->count();

    return $qb->getQuery()->execute();
  }

  private function queryToArray($qb)
  {
    return $qb->hydrate(false)->getQuery()->execute()->toArray();
  }

  private function filterVisibles($qb)
  {
    // fetching pendings and validated
    $qb->field('status')->gte(ElementStatus::PendingModification);
    // removing element withtout category or withtout geolocation
    $qb->field('moderationState')->notIn(array(ModerationState::GeolocError, ModerationState::NoOptionProvided));
    return $qb;
  }
}


