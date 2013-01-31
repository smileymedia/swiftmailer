<?php
/**
 * Sends Messages over SMTP with ESMTP support.
 *
 * @package    Swift
 * @subpackage Transport
 * @author     Chris Corbyn
 */
class Swift_AwsSmtpTransport extends Swift_SmtpTransport
{
    /**
     * Create a new SmtpTransport instance.
     *
     * @param string $host
     * @param int    $port
     * @param int    $security
     *
     * @return Swift_SmtpTransport
     */
    public static function newInstance(
        $host = 'localhost', $port = 25, $security = null
    )
    {
        return new self($host, $port, $security);
    }

}
