#!/usr/bin/env php
<?php

use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";

class RenderThumbnails extends Maintenance {

	/** @var LocalRepo */
	private $localRepo;

	/** @var int[] Value of $wgThumbLimits */
	private $imageLimits;

	/** @var mixed[] Value of $wgGalleryOptions */
	private $galleryOptions;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Render thumbnails of files.' );
		$this->setBatchSize( 10 );
		$this->addOption(
			'title',
			'Render thumbnails for this file. Can be specified multiple times.',
			false,
			true,
			't',
			true
		);
	}

	/**
	 * @return bool|null|void True for success, false for failure. Not returning
	 *   a value, or returning null, is also interpreted as success. Returning
	 *   false for failure will cause doMaintenance.php to exit the process
	 *   with a non-zero exit status.
	 */
	public function execute() {
		$services = MediaWikiServices::getInstance();
		$this->localRepo = $services->getRepoGroup()->getLocalRepo();
		$this->imageLimits = $services->getMainConfig()->get( 'ImageLimits' );
		$this->galleryOptions = $services->getMainConfig()->get( 'GalleryOptions' );

		// Render specific files.
		$titles = $this->getOption( 'title' );
		if ( $titles ) {
			foreach ( $titles as $title ) {
				$this->processOne( $title );
			}
			return true;
		}

		// Or loop through and render all files.
		$dbr = $this->getDB( DB_REPLICA );
		$currentBatch = 0;
		do {
			$opts = [ 'LIMIT' => $this->getBatchSize(), 'OFFSET' => $this->getBatchSize() * $currentBatch ];
			$images = $dbr->select( 'image', 'img_name', [], __METHOD__, $opts );
			foreach ( $images as $imageRow ) {
				$this->processOne( $imageRow->img_name );
			}
			$currentBatch++;
		} while ( $images->numRows() > 0 );
	}

	/**
	 * @param string $imageName
	 */
	private function processOne( string $imageName ) {
		global $wgMaxImageArea;
		$img = $this->localRepo->newFile( $imageName );
		if ( !$img->exists() ) {
			$this->output( "File not found: $imageName\n" );
			return;
		}
		// Increase the max image area to something large.
		$wgMaxImageArea = 10000 * 10000;
		// All sizes from $wgImageLimits.
		foreach ( $this->imageLimits as $imageLimit ) {
			$this->transformToSize( $img, $imageLimit[0], $imageLimit[1] );
		}
		// Gallery sizes (including categories).
		$this->transformToSize( $img, $this->galleryOptions['imageWidth'], $this->galleryOptions['imageHeight'] );
	}

	/**
	 * @param LocalFile $img
	 * @param int $width
	 * @param int $height
	 */
	private function transformToSize( LocalFile $img, $width, $height ) {
		$params = [ 'width' => $width, 'height' => $height ];
		$transformed = $img->transform( $params, File::RENDER_NOW );
		if ( $transformed === false ) {
			$this->error( "Unable to transform {$img->getName()}\n" );
			return;
		} elseif ( $transformed instanceof MediaTransformOutput && $transformed->isError() ) {
			$this->error( "Unable to transform {$img->getName()} because:\n" . $transformed->getHtmlMsg() );
			return;
		}
		$this->output( "{$img->getName()} rendered within {$width}x{$height} to {$img->getThumbPath()}\n" );
	}
}

$maintClass = RenderThumbnails::class;
require_once RUN_MAINTENANCE_IF_MAIN;
