<?php

namespace Mpom\Command;

use Mpom\CommandAbstract;
use Mpom\ConversationSegment;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Import extends CommandAbstract
{

    public function run()
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

            $segment = new ConversationSegment($this->gmail, $this->dbh, $this->statusDbh, $this->output, $this->config);

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
            if (isset($progress)) {
                $progress->advance();
            }
        }

        if (isset($progress)) {
            $progress->finish();
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
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->output->writeln("Inserting conversation: " . $segment->getConvoId());
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

}