<?php

namespace SearchVue\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Class to retrieve media information to populate the Search Preview
 * GET /searchvue/v0/media/{qid}
 */
class GetSearchVueMedia extends SimpleHandler {

	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	/** @var string */
	private $externalMediaSearchUri;

	/** @var string */
	private $searchFilterForQID;

	/** @var string */
	private $mediaRepositorySearchUri;

	/**
	 * @param Config $mainConfig
	 * @param HttpRequestFactory $httpRequestFactory
	 */
	public function __construct(
		Config $mainConfig,
		HttpRequestFactory $httpRequestFactory
	) {
		$this->externalMediaSearchUri = $mainConfig->get( 'QuickViewMediaRepositoryApiBaseUri' );
		$this->searchFilterForQID = $mainConfig->get( 'QuickViewSearchFilterForQID' );
		$this->mediaRepositorySearchUri = $mainConfig->get( 'QuickViewMediaRepositorySearchUri' );
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @param string $qid
	 * @return Response
	 */
	public function run( $qid ) {
		$requests = [];
		$handlers = [];

		if (
			isset( $this->externalMediaSearchUri ) &&
			isset( $this->mediaRepositorySearchUri ) &&
			isset( $this->searchFilterForQID )
		) {
			$searchTerm = $this->generateSearchTerm( $qid );
			$requests['media'] = $this->getMediaRequest( $searchTerm );
			$handlers['media'] = function ( $response ) use ( $searchTerm ) {
				$data = json_decode( $response['response']['body'], true ) ?: [];
				return array_merge(
					$data,
					[ 'searchlink' => $this->generateSearchLink( $searchTerm ) ],
				);
			};
		}

		$results = [];
		if ( $requests ) {
			$responses = $this->httpRequestFactory->createMultiClient()->runMulti( $requests );
			$results = $this->transformResponses( $responses, $handlers );
		}

		return $this->getResponseFactory()->createJson( $results );
	}

	public function needsWriteAccess() {
		return false;
	}

	/**
	 * Request to retrieve commons images, to be shown in the search preview.
	 *
	 * @param string $searchTerm
	 * @return array
	 */
	private function getMediaRequest( $searchTerm ) {
		$payload = [
			'action' => 'query',
			'format' => 'json',
			'generator' => 'search',
			'gsrsearch' => 'filetype:bitmap|drawing -fileres:0 ' . $searchTerm,
			'gsrnamespace' => 6, // NS_FILE
			'gsrlimit' => 7,
			'prop' => 'imageinfo',
			'iiprop' => 'url',
			'iiurlwidth' => 400
		];

		return [
			'method' => 'GET',
			'url' => $this->externalMediaSearchUri . '?' . http_build_query( $payload ),
		];
	}

	/**
	 * @param array $responses
	 * @param array $handlers
	 * @return array
	 */
	private function transformResponses( array $responses, array $handlers ) {
		if ( array_diff_key( $responses, $handlers ) || array_diff_key( $handlers, $responses ) ) {
			throw new \InvalidArgumentException( 'Not all requests have handlers or vice versa' );
		}

		$results = [];
		foreach ( $responses as $key => $response ) {
			$results[ $key ] = $handlers[ $key ]( $response );
		}

		return $results;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'qid' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/**
	 * Generate the search term using configuration settings
	 * @param string $qid
	 * @return string
	 */
	private function generateSearchTerm( $qid ) {
		return sprintf( $this->searchFilterForQID, $qid );
	}

	/**
	 * Generate the search link using the search term.
	 * @param string $searchTerm
	 * @return string
	 */
	private function generateSearchLink( $searchTerm ) {
		return sprintf( $this->mediaRepositorySearchUri, urlencode( $searchTerm ) );
	}
}
