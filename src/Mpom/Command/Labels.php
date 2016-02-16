<?php

namespace Mpom\Command;

use Google_Service_Gmail;
use Mpom\CommandAbstract;

class Labels extends CommandAbstract
{

    public function run()
    {
        $this->initGmailConnection();
        $service = new Google_Service_Gmail($this->gmail->getClient());
        $results = $service->users_labels->listUsersLabels('me');
        $labels = [];

        if (count($results->getLabels()) == 0) {
            $this->output->writeln("No labels found.");
        } else {
            $this->output->writeln("Labels:");
            foreach ($results->getLabels() as $label) {
                $labels[$label->getName()] = $label->getId();
            }
            asort($labels);
            foreach ($labels as $key => $value) {
                $this->output->writeln(sprintf("%-50s %s", $key, $value));
            }
        }

    }

}