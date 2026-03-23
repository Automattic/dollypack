<?php

return array(
	'modules'  => array(
		'docs'               => array(
			'README.md',
		),
		'package_helper'     => array(
			'includes/class-dollypack-package-helper.php',
		),
		'runtime'            => array(
			'includes/class-dollypack-crypto.php',
			'includes/class-dollypack-ability.php',
			'includes/class-dollypack-package-helper.php',
			'includes/class-dollypack-runtime.php',
			'includes/class-settings.php',
		),
		'core_bootstrap'     => array(
			array(
				'source'      => 'packages/core/bootstrap.php',
				'destination' => 'bootstrap.php',
			),
			array(
				'source'      => 'packages/core/dollypack-core.php',
				'destination' => 'dollypack-core.php',
			),
		),
		'github_bootstrap'   => array(
			array(
				'source'      => 'packages/github/bootstrap.php',
				'destination' => 'bootstrap.php',
			),
			array(
				'source'      => 'packages/github/dollypack-github.php',
				'destination' => 'dollypack-github.php',
			),
		),
		'google_bootstrap'   => array(
			array(
				'source'      => 'packages/google/bootstrap.php',
				'destination' => 'bootstrap.php',
			),
			array(
				'source'      => 'packages/google/dollypack-google.php',
				'destination' => 'dollypack-google.php',
			),
		),
		'full_bootstrap'     => array(
			array(
				'source'      => 'packages/full/bootstrap.php',
				'destination' => 'bootstrap.php',
			),
			array(
				'source'      => 'packages/full/dollypack-full.php',
				'destination' => 'dollypack-full.php',
			),
		),
		'core_abilities'     => array(
			'abilities/wp-remote-request.php',
		),
		'github_group'       => array(
			'includes/class-dollypack-github-ability.php',
			'abilities/github-read.php',
			'abilities/github-notifications.php',
			'abilities/github-search.php',
			'abilities/github-write.php',
		),
		'google_group'       => array(
			'includes/class-dollypack-google-ability.php',
			'abilities/google-calendar-read.php',
		),
	),
	'packages' => array(
		'dollypack-core'   => array(
			'main_file' => 'dollypack-core.php',
			'modules' => array(
				'docs',
				'package_helper',
				'runtime',
				'core_bootstrap',
				'core_abilities',
			),
		),
		'dollypack-github' => array(
			'main_file' => 'dollypack-github.php',
			'modules' => array(
				'docs',
				'package_helper',
				'github_bootstrap',
				'github_group',
			),
		),
		'dollypack-google' => array(
			'main_file' => 'dollypack-google.php',
			'modules' => array(
				'docs',
				'package_helper',
				'google_bootstrap',
				'google_group',
			),
		),
		'dollypack-full'   => array(
			'main_file' => 'dollypack-full.php',
			'modules' => array(
				'docs',
				'runtime',
				'full_bootstrap',
				'core_abilities',
				'github_group',
				'google_group',
			),
		),
	),
);
