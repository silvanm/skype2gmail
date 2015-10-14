<?php

include( "vendor/autoload.php" );

error_reporting(E_ALL);

$config = [
    'gmail_application_name'    => 'SkypeToGmail',
    'gmail_credentials_path'    => 'config/skype-to-gmail.json',
    'gmail_client_secrets_path' => 'config/client_secret.json',
    'me'                        => 'silvanm75',
    'dsn'                       => 'sqlite:'.__DIR__."/../../../main.db",
    'statusDbDsn'               => 'sqlite:'.__DIR__."/data/statusDb.db",
    'inactivityTriggerSeconds'  => 3600,
];

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

$console = new Application();

$skypeToGmail = new Mpom\SkypeToGmail($config);

$console
    ->register('sync')
    ->setDescription('Initializes the Statusdb ')
    ->setCode(function (InputInterface $input, OutputInterface $output) use ($skypeToGmail) {
        $skypeToGmail->getConversations();
    })
;

$console
    ->register('initdb')
    ->setDescription('Initializes the Statusdb ')
    ->setCode(function (InputInterface $input, OutputInterface $output) use ($skypeToGmail) {
        $skypeToGmail->initStatusDb();
    })
;

$console->run();
