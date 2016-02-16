<?php

include("vendor/autoload.php");

error_reporting(E_ALL);

$config = include "config/config.php";

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

$console = new Application("SkypeToGmail");

$console
    ->register('import')
    ->setDescription('Import the Skype conversations into Gmail')
    ->addOption(
        'progress',
        'p',
        InputOption::VALUE_NONE,
        'Show a progressbar'
    )
    ->setCode(function(InputInterface $input, OutputInterface $output) use ($config) {
        $skypeToGmail = new Mpom\SkypeToGmail($config, $input, $output);
        $skypeToGmail->run();
    });

$console
    ->register('labels')
    ->setDescription('Show all Gmail labels')
    ->setCode(function(InputInterface $input, OutputInterface $output) use ($config) {
        $output->writeln("<info>Showing all possible Gmail labels of your account</info>");
        $skypeToGmail = new Mpom\SkypeToGmail($config, $input, $output);
        $skypeToGmail->run();
    });

$console
    ->register('init')
    ->setDescription('Initializes the StatusDB and Gmail API')
    ->setCode(function(InputInterface $input, OutputInterface $output) use ($config) {
        $output->writeln("<info>Initializing StatusDb</info>");
        $skypeToGmail = new Mpom\SkypeToGmail($config, $input, $output);
        $skypeToGmail->run();
        $output->writeln("Initialization successful");
    });

$console->run();
