<?php

namespace Biopen\FournisseurBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

use Doctrine\ORM\EntityRepository;

use Biopen\FournisseurBundle\Form\JourHoraireType;


class HoraireType extends AbstractType
{
  /**
   * @param FormBuilderInterface $builder
   * @param array $options
   */
  public function buildForm(FormBuilderInterface $builder, array $options)
  {
      $builder->add('Lundi', JourHoraireType::class)
              ->add('Mardi', JourHoraireType::class)
              ->add('Mercredi', JourHoraireType::class)
              ->add('Jeudi', JourHoraireType::class)
              ->add('Vendredi', JourHoraireType::class)
              ->add('Samedi', JourHoraireType::class)
              ->add('Dimanche', JourHoraireType::class) ;
  }
  
  /**
   * @param OptionsResolver $resolver
   */
  public function configureOptions(OptionsResolver $resolver)
  {
      $resolver->setDefaults(array(
          'data_class' => 'Biopen\FournisseurBundle\Classes\Horaire'
      ));
  }

  /**
  * @return string
  */
  public function getName()
  {
    return 'biopen_fournisseurbundle_horaire';
  }
}
