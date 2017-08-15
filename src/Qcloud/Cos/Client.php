<?php

namespace Qcloud\Cos;

use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Client as GSClient;
use Guzzle\Common\Collection;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestInterface;
use Qcloud\Cos\Signature;

class Client extends GSClient {
    const VERSION = '0.0.3';

    private $region;       // string: region.
    private $credentials;
    private $appId;        // string: application id.
    private $secretId;     // string: secret id.
    private $secretKey;    // string: secret key.
    private $timeout;      // int: timeout
    private $connect_timeout; // int: connect_timeout
    private $signature;

    public function __construct($config) {
        $this->region = isset($config['region']) ? $config['region'] : '';
        $this->credentials = $config['credentials'];
        $this->appId = $config['credentials']['appId'];
        $this->secretId = $config['credentials']['secretId'];
        $this->secretKey = $config['credentials']['secretKey'];

        $this->timeout = isset($config['timeout']) ? $config['timeout'] : 600;
        $this->connect_timeout = isset($config['connect_timeout']) ? $config['connect_timeout'] : 600;
        $this->signature = new signature($this->secretId, $this->secretKey);
        parent::__construct(
                'http://' . $this->region . '.myqcloud.com/',    // base url
                array('request.options' => array('timeout' => 5, 'connect_timeout' => $this->connect_timeout),
                    )); // show curl verbose or not

        $desc = ServiceDescription::factory(Service::getService());
        $this->setDescription($desc);
        $this->setUserAgent('cos-php-sdk-v5/' . Client::VERSION, true);

        $this->addSubscriber(new ExceptionListener());
        $this->addSubscriber(new SignatureListener($this->secretId, $this->secretKey));
        $this->addSubscriber(new BucketStyleListener($this->appId));

        // Allow for specifying bodies with file paths and file handles
        $this->addSubscriber(new UploadBodyListener(array('PutObject', 'UploadPart')));
    }

    public function __destruct() {
    }

    public function __call($method, $args) {
        return parent::__call(ucfirst($method), $args);
    }
    /**
     * Create a pre-signed URL for a request
     *
     * @param RequestInterface     $request Request to generate the URL for. Use the factory methods of the client to
     *                                      create this request object
     * @param int|string|\DateTime $expires The time at which the URL should expire. This can be a Unix timestamp, a
     *                                      PHP DateTime object, or a string that can be evaluated by strtotime
     *
     * @return string
     * @throws InvalidArgumentException if the request is not associated with this client object
     */
    public function createPresignedUrl(RequestInterface $request, $expires)
    {
        if ($request->getClient() !== $this) {
            throw new InvalidArgumentException('The request object must be associated with the client. Use the '
                . '$client->get(), $client->head(), $client->post(), $client->put(), etc. methods when passing in a '
                . 'request object');
        }
        return $this->signature->createPresignedUrl($request, $this->credentials, $expires);
    }
    public function getObjectUrl($bucket, $key, $expires = null, array $args = array())
    {
        $command = $this->getCommand('GetObject', $args + array('Bucket' => $bucket, 'Key' => $key));

        if ($command->hasKey('Scheme')) {
            $scheme = $command['Scheme'];
            $request = $command->remove('Scheme')->prepare()->setScheme($scheme)->setPort(null);
        } else {
            $request = $command->prepare();
        }

        return $expires ? $this->createPresignedUrl($request, $expires) : $request->getUrl();
    }
    public function upload($bucket, $key, $body, $acl = 'private', $options = array()) {
        $body = EntityBody::factory($body);
        $options = Collection::fromConfig(array_change_key_case($options), array(
                    'min_part_size' => MultipartUpload::MIN_PART_SIZE,
                    'params'        => array()));

        if ($body->getSize() < $options['min_part_size']) {
            // Perform a simple PutObject operation
            return $this->putObject(array(
                        'Bucket' => $bucket,
                        'Key'    => $key,
                        'Body'   => $body,
                        'ACL'    => $acl
                        ) + $options['params']);
        }

        $multipartUpload = new MultipartUpload($this, $body, $options['min_part_size'], array(
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'Body'   => $body,
                    'ACL'    => $acl
                    ) + $options['params']);

        return $multipartUpload->performUploading();
    }

    public static function encodeKey($key) {
        return str_replace('%2F', '/', rawurlencode($key));
    }

    public static function explodeKey($key) {
        // Remove a leading slash if one is found
        return explode('/', $key && $key[0] == '/' ? substr($key, 1) : $key);
    }
}