<?php

namespace Detain\MyAdmin;

use Amp\Artax\Client;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Deferred;
use Amp\Promise;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use Meng\AsyncSoap\SoapClientInterface;
use Meng\Soap\HttpBinding\HttpBinding;
use Zend\Diactoros\Response as PsrResponse;
use Zend\Diactoros\Stream;
use function Amp\wait;

/**
 * Class SoapClient
 *
 * @package Detain\MyAdmin
 */
class SoapClient implements SoapClientInterface
{
	private $deferredHttpBinding;
	private $client;

	/**
	 * SoapClient constructor.
	 *
	 * @param \Amp\Artax\Client $client
	 * @param \Amp\Promise      $httpBindingPromise
	 */
	public function __construct(Client $client, Promise $httpBindingPromise)
	{
		$this->client = $client;
		$this->deferredHttpBinding = $httpBindingPromise;
	}

	/**
	 * @param $name
	 * @param $arguments
	 * @return mixed
	 */
	public function __call($name, $arguments)
	{
		return $this->callAsync($name, $arguments);
	}

	/**
	 * @param            $name
	 * @param array      $arguments
	 * @param array|null $options
	 * @param null       $inputHeaders
	 * @param array|null $outputHeaders
	 * @return mixed
	 */
	public function call($name, array $arguments, array $options = null, $inputHeaders = null, array &$outputHeaders = null)
	{
		$promise = $this->callAsync($name, $arguments, $options, $inputHeaders, $outputHeaders);
		return $promise->wait();
	}

	/**
	 * @param            $name
	 * @param array      $arguments
	 * @param array|null $options
	 * @param null       $inputHeaders
	 * @param array|null $outputHeaders
	 * @return mixed
	 */
	public function callAsync($name, array $arguments, array $options = null, $inputHeaders = null, array &$outputHeaders = null)
	{
		$deferredResult = new Deferred;
		$this->deferredHttpBinding->when(
			function (\Exception $error = null, $httpBinding) use ($deferredResult, $name, $arguments, $options, $inputHeaders, &$outputHeaders) {
				if ($error) {
					$deferredResult->fail($error);
				} else {
					$request = new Request;
					/** @var HttpBinding $httpBinding */
					$psrRequest = $httpBinding->request($name, $arguments, $options, $inputHeaders);
					$request->setMethod($psrRequest->getMethod());
					$request->setUri($psrRequest->getUri());
					$request->setAllHeaders($psrRequest->getHeaders());
					$request->setBody($psrRequest->getBody()->__toString());
					$psrRequest->getBody()->close();

					$this->client->request($request)->when(
						function (\Exception $error = null, $response) use ($name, &$outputHeaders, $deferredResult, $httpBinding) {
							if ($error) {
								$deferredResult->fail($error);
							} else {
								$bodyStream = new Stream('php://temp', 'r+');
								/** @var Response $response */
								$bodyStream->write($response->getBody());
								$bodyStream->rewind();
								$psrResponse = new PsrResponse($bodyStream, $response->getStatus(), $response->getAllHeaders());

								try {
									$deferredResult->succeed($httpBinding->response($psrResponse, $name, $outputHeaders));
								} catch (\Exception $e) {
									$deferredResult->fail($e);
								} finally {
									$psrResponse->getBody()->close();
								}

							}
						}
					);
				}
			}
		);

		return $deferredResult->promise();
	}
}

