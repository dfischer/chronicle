<?php
declare(strict_types=1);
namespace ParagonIE\Chronicle\Process;

use ParagonIE\Chronicle\Chronicle;
use ParagonIE\Chronicle\Exception\{
    ChainAppendException,
    FilesystemException
};
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Class Attest
 *
 * This process publishes the latest hash of each replicated Chronicle
 * onto the local instance, to create an immutable record of the replicated
 * Chronicles and provide greater resilience against malicious tampering.
 *
 * @package ParagonIE\Chronicle\Process
 */
class Attest
{
    /** @var array<string, string> */
    protected $settings;

    /**
     * Attest constructor.
     * @param array<string, string> $settings
     */
    public function __construct(array $settings = [])
    {
        if (empty($settings)) {
            $settings = Chronicle::getSettings();
        }
        $this->settings = $settings;
    }

    /**
     * Do we need to run the attestation process?
     *
     * @return bool
     *
     * @throws FilesystemException
     */
    public function isScheduled(): bool
    {
        /** @var string $query */
        $query = 'SELECT count(id) FROM ' . Chronicle::getTableName('replication_sources');
        if (!Chronicle::getDatabase()->exists($query)) {
            return false;
        }
        if (!isset($this->settings['scheduled-attestation'])) {
            return false;
        }
        if (!\file_exists(CHRONICLE_APP_ROOT . '/local/replication-last-run')) {
            return true;
        }
        $lastRun = \file_get_contents(CHRONICLE_APP_ROOT . '/local/replication-last-run');
        if (!\is_string($lastRun)) {
            throw new FilesystemException('Could not read replication last run file');
        }

        $now = new \DateTimeImmutable('NOW');
        $runTime = new \DateTimeImmutable($lastRun);

        // Return true only if the next scheduled run has come to pass.
        $interval = \DateInterval::createFromDateString($this->settings['scheduled-attestation']);
        $nextRunTime = $runTime->add($interval);
        return $nextRunTime < $now;
    }

    /**
     * @return void
     *
     * @throws ChainAppendException
     * @throws FilesystemException
     * @throws \SodiumException
     */
    public function run()
    {
        $now = (new \DateTime('NOW'))->format(\DateTime::ATOM);

        /** @var int|bool $lock */
        $lock = \file_put_contents(
            CHRONICLE_APP_ROOT . '/local/replication-last-run',
            $now
        );
        if (!\is_int($lock)) {
            throw new FilesystemException('Cannot save replication last run file.');
        }
        $this->attestAll();
    }

    /**
     * @return array
     *
     * @throws ChainAppendException
     * @throws FilesystemException
     * @throws \SodiumException
     * @throws \TypeError
     */
    public function attestAll(): array
    {
        $hashes = [];
        $db = Chronicle::getDatabase();
        /** @var array<int, array<string, string>> $rows */
        $rows = $db->run('SELECT id, uniqueid FROM ' . Chronicle::getTableName('replication_sources'));
        /** @var array<string, string> $row */
        foreach ($rows as $row) {
            /** @var array<string, string> $latest */
            $latest = $db->row(
                "SELECT
                     currhash,
                     summaryhash
                 FROM
                     " . Chronicle::getTableName('replication_chain') . "
                 WHERE
                     source = ?
                 ORDER BY id DESC
                 LIMIT 1",
                $row['id']
            );
            $latest['source'] = $row['uniqueid'];
            $hashes[] = $latest;
        }

        // Build the message
        /** @var string $message */
        $message = \json_encode(
            [
                'version' => Chronicle::VERSION,
                'datetime' => (new \DateTime())->format(\DateTime::ATOM),
                'replication-hashes' => $hashes
            ],
            JSON_PRETTY_PRINT
        );
        if (!\is_string($message)) {
            throw new \TypeError('Invalid messsage');
        }

        // Sign the message:
        $signature = Base64UrlSafe::encode(
            \ParagonIE_Sodium_Compat::crypto_sign_detached(
                $message,
                Chronicle::getSigningKey()->getString(true)
            )
        );

        // Write the message onto the local Blakechain
        return Chronicle::extendBlakechain(
            $message,
            $signature,
            Chronicle::getSigningKey()->getPublicKey()
        );
    }
}
