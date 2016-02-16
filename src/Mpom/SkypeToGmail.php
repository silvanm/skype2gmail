<?php

namespace Mpom;

use Google_Service_Gmail;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SkypeToGmail
{

    protected $config;

    /** @var \PDO */
    protected $dbh;

    /** @var  \PDO */
    protected $statusDbh;

    /** @var  Gmail */
    protected $gmail;

    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    public function __construct($config, InputInterface $input, OutputInterface $output)
    {
        $this->config = $config;

        $this->input = $input;
        $this->output = $output;
    }

    public function initDbConnection()
    {
        try {
            $this->dbh = new \PDO($this->config['skypeDsn']);
        } catch (\Exception $e) {
            $this->output->writeln(
                sprintf("<error>Error during setting up connection to Skype-database %s. DB-String is %s.</error>",
                $e->getMessage(), $this->config['skypeDsn']));
            return false;
        }

        try {
            $this->statusDbh = new \PDO($this->config['statusDbDsn']);
        } catch (\Exception $e) {
            $this->output->writeln(
                sprintf("<error>Error during setting up connection to Skype-database %s. DB-String is %s.</error>",
                    $e->getMessage(), $this->config['statusDbDsn']));
            return false;
        }

        return true;
    }

    /**
     * @return Gmail
     */
    public function initGmailConnection()
    {
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln("Init Gmail Connection");
        }

        $gmail = new Gmail($this->config);
        $gmail->getClient();
        $this->gmail = $gmail;
        return $gmail;
    }

    public function getConversations()
    {
        if (!$this->initDbConnection()) {
            return false;
        }
        $this->initGmailConnection();

        // Find all conversations which are finished (= last activity :timeout ago)
        $convoQuery = $this->dbh->query(
            "SELECT id, displayname
        FROM Conversations WHERE last_activity_timestamp < (strftime('%s', 'now') - :timeout)
        "
        );
        $convoQuery->execute([':timeout' => $this->config['inactivityTriggerSeconds']]);

        $conversations = $convoQuery->fetchAll();

        if ($this->input->getOption('progress')) {
            $progress = new ProgressBar($this->output, count($conversations));
            $progress->start();
        }

        foreach ($conversations as $conversation) {

            $minTimestamp = $this->getLastMessageTimestampByConversation($conversation['id']);

            if (is_null($minTimestamp)) {
                $minTimestamp = 0;
            }

            // Find all messages which are not stored yet.
            $messageQuery = $this->dbh->query(
                "SELECT id, convo_id, timestamp, author, body_xml
                FROM Messages
               WHERE convo_id = :convoid
               AND timestamp > :timestamp
               ORDER BY timestamp
                "
            );
            $messageQuery->execute([':convoid' => $conversation['id'], ':timestamp' => $minTimestamp]);

            $lastTimestamp = null;
            foreach ($messageQuery->fetchAll() as $message) {

                // Does a new segment start here?
                if ($lastTimestamp == null || ($message['timestamp'] - $lastTimestamp) > $this->config['inactivityTriggerSeconds']) {
                    if (!empty($segment)) {
                        $this->storeSegmentAndUpdatePointer($segment);
                    }
                    $segment = new ConversationSegment($this->gmail, $this->dbh, $this->statusDbh, $this->output, $this->config);

                }
                $segment->addMessage(
                    $message['timestamp'],
                    $message['author'],
                    $message['body_xml'],
                    $message['convo_id'],
                    $message['id'],
                    $conversation['displayname']
                );
                $lastTimestamp = $message['timestamp'];
            }
            if (isset($segment)) {
                $this->storeSegmentAndUpdatePointer($segment);
            }
            if ($this->input->getOption('progress')) {
                $progress->advance();
            }
            $segment = null;
        }

        if ($this->input->getOption('progress')) {
            $progress->finish();
        }

        return true;
    }

    public function initStatusDb()
    {

        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln("Init status DB");
        }

        if (!$this->initDbConnection()) {
            return false;
        }

        // See if database has been initialized.
        $query = $this->statusDbh->query("SELECT name FROM sqlite_master WHERE name='importPointer'");
        $query->execute();
        if ($query->rowCount() == 0) {
            $this->statusDbh->exec(
                "CREATE TABLE `importPointer` (
            	`convo_id`	INTEGER,
            	`timestamp`	INTEGER,
            	PRIMARY KEY(convo_id)
            )"
            );

            $this->statusDbh->exec(
                "CREATE TABLE `skypenameToEmail` (
                    `skypename`	TEXT,
                    `email`	TEXT,
                    `name`	TEXT,
                    PRIMARY KEY(skypename)
                   );"
            );

        }

        return true;
    }

    /**
     * @param $id int convo_id
     *
     * @return int
     */
    protected function getLastMessageTimestampByConversation($id)
    {
        $query = $this->statusDbh->prepare("SELECT timestamp FROM importPointer WHERE convo_id = :convoid");
        $query->execute([':convoid' => $id]);

        if ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            return $row['timestamp'];
        }

        return null;
    }

    /**
     * Update Status database for this conversation segment
     * @param ConversationSegment $segment
     * @throws \Exception
     */
    protected function storeSegmentAndUpdatePointer(ConversationSegment $segment)
    {
        if ($segment->getNumberOfMessages() > 1) {
            $segment->store();

            $query = $this->statusDbh->prepare(
                "SELECT convo_id, timestamp FROM importPointer WHERE convo_id = :convoid"
            );
            $query->execute([':convoid' => $segment->getConvoId()]);

            // There is already a row
            if ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                if ($row['timestamp'] < $segment->getMaxTimestamp()) {
                    $query = $this->statusDbh->prepare(
                        "UPDATE importPointer SET timestamp=:timestamp WHERE convo_id = :convoid"
                    );
                    $result = $query->execute(
                        [':convoid' => $segment->getConvoId(), ':timestamp' => $segment->getMaxTimestamp()]
                    );
                } else {
                    $result = true;
                }
            } else {
                // New row for this conversation
                $query = $this->statusDbh->prepare(
                    "INSERT INTO importPointer(`convo_id`, `timestamp`) VALUES (:convoid, :timestamp) "
                );
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $this->output->writeln("inserting : " . $segment->getConvoId());
                }

                $result = $query->execute(
                    [':convoid' => $segment->getConvoId(), ':timestamp' => $segment->getMaxTimestamp()]
                );
            }
            if (!$result) {
                throw new \Exception("PDO-Exception: " . print_r($this->statusDbh->errorInfo(), true));
            }
        }
    }

    public function showLabels()
    {
        $this->initGmailConnection();
        $service = new Google_Service_Gmail($this->gmail->getClient());
        $results = $service->users_labels->listUsersLabels('me');

        if (count($results->getLabels()) == 0) {
            $this->output->writeln("No labels found.");
        } else {
            $this->output->writeln("Labels:");
            foreach ($results->getLabels() as $label)  {
                $labels[$label->getName()] = $label->getId();
            }
            asort($labels);
            foreach ($labels as $key => $value) {
                $this->output->writeln(sprintf("%-50s %s", $key, $value));
            }
        }

    }

}