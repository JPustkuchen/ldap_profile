<?php

namespace Drupal\ldap_profile\EventSubscriber;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ldap_servers\LdapUserManager;
use Drupal\ldap_servers\Logger\LdapDetailLog;
use Drupal\ldap_servers\Processor\TokenProcessor;
use Drupal\ldap_user\Event\LdapUserLoginEvent;
use Drupal\ldap_user\FieldProvider;
use Drupal\ldap_user\Processor\DrupalUserProcessor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles processing of a profile from LDAP to Drupal.
 */
class LdapProfileSyncSubscriber implements EventSubscriberInterface {
  /**
   * @var ConfigFactory
   */
  protected $config;

  /**
   * @var FieldProvider
   */
  protected $fieldProvider;

  /**
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var LdapUserManager
   */
  protected $ldapUserManager;

  /**
   * @var DrupalUserProcessor
   */
  protected $userProcessor;

  /**
   * @var TokenProcessor
   */
  protected $tokenProcessor;

  /**
   * @var LdapDetailLog
   */
  protected $detailLog;

  /**
   * LdapProfileSyncSubscriber constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   Config factory.
   * @param \Drupal\ldap_servers\Logger\LdapDetailLog $detail_log
   *   Detail log.
   * @param \Drupal\ldap_servers\Processor\TokenProcessor $token_processor
   *   Token processor.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\ldap_servers\LdapUserManager $ldap_user_manager
   *   LDAP user manager.
   * @param \Drupal\ldap_user\FieldProvider $field_provider
   *   Field Provider.
   * @param \Drupal\ldap_user\Processor\DrupalUserProcessor $user_processor
   *   LDAP drupal user processor
   */
  public function __construct(
    ConfigFactory $config_factory,
    LdapUserManager $ldap_user_manager,
    EntityTypeManagerInterface $entity_type_manager,
    LdapDetailLog $detail_log,
    FieldProvider $field_provider,
    DrupalUserProcessor $user_processor,
    TokenProcessor $token_processor
    ) {
    $this->config = $config_factory->get('ldap_user.settings');
    $this->ldapUserManager = $ldap_user_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->detailLog = $detail_log;
    $this->fieldProvider = $field_provider;
    $this->userProcessor = $user_processor;
    $this->tokenProcessor = $token_processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[LdapUserLoginEvent::EVENT_NAME] = ['syncProfileFields'];
    return $events;
  }

  /**
   * Event subscriber callback: Set profile fields.
   *
   * @param \Drupal\ldap_user\Event\LdapUserLoginEvent $event
   *   The dispatched event.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function syncProfileFields(LdapUserLoginEvent $event) : void {
    $account = $event->account;
    /** @var \Drupal\ldap_servers\Entity\Server $server */
    $server = $this->entityTypeManager
      ->getStorage('ldap_server')
      ->load($this->config->get('drupalAcctProvisionServer'));

    // Get an LDAP user from the LDAP server.
    if ($this->config->get('drupalAcctProvisionServer')) {
      $this->ldapUserManager->setServer($server);
      $ldapEntry = $this->ldapUserManager->getUserDataByIdentifier($account->getAccountName());
    }

    if (!$ldapEntry) {
      $this->detailLog->log(
        '@username: Failed to find associated LDAP entry for username in provision.',
        ['@username' => $account->getAccountName()],
        'ldap-user'
      );
    }
    else {
      $event = $this->userProcessor::EVENT_SYNC_TO_DRUPAL_USER;
      $mappings = $this->fieldProvider->getConfigurableAttributesSyncedOnEvent($event);

      foreach ($mappings as $key => $mapping) {
        $value = $this->tokenProcessor->ldapEntryReplacementsForDrupalAccount($ldapEntry, $mapping->getLdapAttribute());

	      // Extract $entity_type and $field_name from $key
      	list($entity_type, $field_name) = $this->parseUserAttributeNames($key);

        if ($entity_type == 'profile') {
          /** @var \Drupal\profile\Entity\ProfileInterface[] $profiles */
          $profiles = $this->entityTypeManager->getStorage('profile')
            ->loadByProperties([
              'uid' => $account->id(),
            ]);
          foreach ($profiles as $profile) {
            if (!empty($field_name) && $profile->hasField($field_name)) {
              $profile->set($field_name, $value);
              $profile->save();
            } else {
              $this->detailLog->log(
                '@username: Failed to find field @field_name for username in provision.',
                ['@field_name' => $field_name, '@username' => $account->getAccountName()],
                'ldap-user'
              );
            }
          }
        }
      }
    }
  }

  /**
   * This is a copy of DrupalUserProcessor::parseUserAttributeNames().
   *
   * Parse user attribute names.
   *
   * @param string $user_attr_key
   *   A string in the form of <attr_type>.<attr_name>[:<instance>] such as
   *   field.lname, property.mail, field.aliases:2.
   *
   * @return array
   *   An array such as array('field','field_user_lname', NULL).
   */
  protected function parseUserAttributeNames(string $user_attr_key) : array {
    // Make sure no [] are on attribute.
    $user_attr_key = trim($user_attr_key, '[]');
    $parts = explode('.', $user_attr_key);
    $attr_type = $parts[0];
    $attr_name = $parts[1] ?? FALSE;

    if ($attr_name) {
      $attr_name_parts = explode(':', $attr_name);
      if (isset($attr_name_parts[1])) {
        $attr_name = $attr_name_parts[0];
      }
    }
    return [$attr_type, $attr_name];
  }

}
