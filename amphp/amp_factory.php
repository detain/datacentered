<?php

namespace Detain\MyAdmin;

use Detain\MyAdmin\SoapClient;
use Amp\Artax\Client;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Deferred;
use Meng\AsyncSoap\SoapClientInterface;
use Meng\Soap\HttpBinding\HttpBinding;
use Meng\Soap\HttpBinding\RequestBuilder;
use Meng\Soap\Interpreter;

/**
 * Class Factory
 *
 * @package Detain\MyAdmin
 */
class Factory
{
	/**
	 * Create an instance of SoapClientInterface asynchronously.
	 *
	 * @param Client $client                An Artax HTTP client.
	 * @param mixed $wsdl                   URI of the WSDL file or NULL if working in non-WSDL mode.
	 * @param array $options                Supported options: location, uri, style, use, soap_version, encoding,
	 *                                      exceptions, classmap, typemap, and feature. HTTP related options should
	 *                                      be configured against $client, e.g., authentication, proxy, user agent,
	 *                                      and connection timeout etc.
	 * @return SoapClientInterface
	 */
	public function create(Client $client, $wsdl, array $options = [])
	{
		$deferredHttpBinding = new Deferred;
		if (null === $wsdl) {
			$deferredHttpBinding->succeed(new HttpBinding(new Interpreter($wsdl, $options), new RequestBuilder));
		} else {
			$wsdlRequest = new Request;
			$wsdlRequest->setMethod('GET')->setUri($wsdl);
			$client->request($wsdlRequest)->when(
				function (\Exception $error = null, $response) use ($deferredHttpBinding, $options) {
					if ($error) {
						$deferredHttpBinding->fail($error);
					} else {
						/** @var Response $response */
						$wsdl = $response->getBody();
						$deferredHttpBinding->succeed(
							new HttpBinding(
								new Interpreter('data://text/plain;base64,'.base64_encode($wsdl), $options),
								new RequestBuilder
							)
						);
					}

				}
			);
		}
		return new SoapClient($client, $deferredHttpBinding->promise());
	}
}
