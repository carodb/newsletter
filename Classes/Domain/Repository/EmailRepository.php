<?php

namespace Ecodev\Newsletter\Domain\Repository;

use Ecodev\Newsletter\Tools;

/**
 * Repository for \Ecodev\Newsletter\Domain\Model\Email
 */
class EmailRepository extends AbstractRepository
{
    protected static $emailCountCache = [];

    /**
     * Returns the email corresponsding to the authCode
     * @param string $authcode
     * @return \Ecodev\Newsletter\Domain\Model\Email
     */
    public function findByAuthcode($authcode)
    {
        $query = $this->createQuery();

        $db = Tools::getDatabaseConnection();
        $escaped = $db->fullQuoteStr($authcode, 'tx_newsletter_domain_model_email');
        $query->statement('SELECT * FROM `tx_newsletter_domain_model_email` WHERE auth_code = ' . $escaped . ' LIMIT 1');

        return $query->execute()->getFirst();
    }

    /**
     * Returns the count of emails for a given newsletter
     * @param int $uidNewsletter
     */
    public function getCount($uidNewsletter)
    {
        // If we have cached result return directly that value to avoid X query for X Links per newsletter
        if (isset(self::$emailCountCache[$uidNewsletter])) {
            return self::$emailCountCache[$uidNewsletter];
        }

        $db = Tools::getDatabaseConnection();
        $count = $db->exec_SELECTcountRows('*', 'tx_newsletter_domain_model_email', 'newsletter = ' . $uidNewsletter);
        self::$emailCountCache[$uidNewsletter] = $count;

        return (int) $count;
    }

    /**
     * Returns all email for a given newsletter
     * @param int $uidNewsletter
     * @param int $start
     * @param int $limit
     * @return \Ecodev\Newsletter\Domain\Model\Email[]
     */
    public function findAllByNewsletter($uidNewsletter, $start, $limit)
    {
        if ($uidNewsletter < 1) {
            return $this->findAll();
        }

        $query = $this->createQuery();
        $query->matching($query->equals('newsletter', $uidNewsletter));
        $query->setLimit($limit);
        $query->setOffset($start);

        return $query->execute();
    }

    /**
     * Register an open email in database and forward the event to RecipientList
     * so it can optionnally do something more
     * @param string $authCode
     */
    public function registerOpen($authCode)
    {
        $db = Tools::getDatabaseConnection();

        // Minimal sanitization before SQL
        $authCode = $db->fullQuoteStr($authCode, 'tx_newsletter_domain_model_email');

        $db->sql_query('UPDATE tx_newsletter_domain_model_email SET open_time = ' . time() . " WHERE open_time = 0 AND auth_code = $authCode");
        $updateEmailCount = $db->sql_affected_rows();

        // Tell the target that he opened the email, but only the first time
        if ($updateEmailCount) {
            $rs = $db->sql_query("
            SELECT tx_newsletter_domain_model_newsletter.recipient_list, tx_newsletter_domain_model_email.recipient_address
            FROM tx_newsletter_domain_model_email
            LEFT JOIN tx_newsletter_domain_model_newsletter ON (tx_newsletter_domain_model_email.newsletter = tx_newsletter_domain_model_newsletter.uid)
            LEFT JOIN tx_newsletter_domain_model_recipientlist ON (tx_newsletter_domain_model_newsletter.recipient_list = tx_newsletter_domain_model_recipientlist.uid)
            WHERE tx_newsletter_domain_model_email.auth_code = $authCode AND recipient_list IS NOT NULL
            LIMIT 1");

            if (list($recipientListUid, $emailAddress) = $db->sql_fetch_row($rs)) {
                $recipientListRepository = $this->objectManager->get(\Ecodev\Newsletter\Domain\Repository\RecipientListRepository::class);
                $recipientList = $recipientListRepository->findByUid($recipientListUid);
                if ($recipientList) {
                    $recipientList->registerOpen($emailAddress);
                }
            }
        }
    }
}
