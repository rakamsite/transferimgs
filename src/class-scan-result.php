<?php

namespace MediaFlattenMigrator;

final class Scan_Result {
	/** @var array<int, array<string, mixed>> */
	private $attachments;

	/** @var array<int, array<string, mixed>> */
	private $files;

	/** @var array<string, array<string, mixed>> */
	private $collisions;

	/**
	 * @param array<int, array<string, mixed>>    $attachments Attachment report rows.
	 * @param array<int, array<string, mixed>>    $files       Referenced file rows.
	 * @param array<string, array<string, mixed>> $collisions  Collisions keyed by target path.
	 */
	public function __construct( array $attachments, array $files, array $collisions ) {
		$this->attachments = $attachments;
		$this->files       = $files;
		$this->collisions  = $collisions;
	}

	/** @return array<int, array<string, mixed>> */
	public function attachments() {
		return $this->attachments;
	}

	/** @return array<int, array<string, mixed>> */
	public function files() {
		return $this->files;
	}

	/** @return array<string, array<string, mixed>> */
	public function collisions() {
		return $this->collisions;
	}

	/** @return array<string, int> */
	public function summary() {
		$missing = array_filter(
			$this->files,
			static function ( $file ) {
				return 'no' === $file['exists'];
			}
		);

		return array(
			'total_attachments' => count( $this->attachments ),
			'total_files'       => count( $this->files ),
			'missing_files'     => count( $missing ),
			'collision_count'   => count( $this->collisions ),
		);
	}
}
