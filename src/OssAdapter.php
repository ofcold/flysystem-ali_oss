<?php

namespace Ofcold\FlysystemOSS;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use OSS\Core\OssException;
use OSS\OssClient;

/**
 *	Class OssAdapter
 *
 *	@link		https://ofcold.ink
 *
 *	@author		Ofcold lab, Inc <support@ofcold.ink>
 *	@author		Olivia Fu <olivia.fu@ofcold.ink>
 *	@author		Bill Li <bill.li@ofcold.ink>
 *
 *	@package	Ofcold\FlysystemOSS\OssAdapter
 */
class OssAdapter extends AbstractAdapter
{
	/**
	 *	@var		array
	 */
	protected static $resultMap = [
		'Body'				=> 'raw_contents',
		'Content-Length'	=> 'size',
		'ContentType'		=> 'mimetype',
		'Size'				=> 'size',
		'StorageClass'		=> 'storage_class',
	];

	/**
	 *	@var		array
	 */
	protected static $metaOptions = [
		'CacheControl',
		'Expires',
		'ServerSideEncryption',
		'Metadata',
		'ACL',
		'ContentType',
		'ContentDisposition',
		'ContentLanguage',
		'ContentEncoding',
	];

	/**
	 *	@var		OssClient
	 */
	protected $ossClient;

	/**
	 *
	 *	@var		string
	 */
	protected $bucket;

	/**
	 *	@var		array
	 */
	protected $options = [
		'Multipart'   => 128
	];

	protected $endpoint;

	/**
	 *	Constructor.
	 *
	 *	@param		OssClient	$client
	 *	@param		string		$bucket
	 *	@param		string		$prefix
	 *	@param		array		$options
	 */
	public function __construct(OssClient $client, string $bucket, string $endpoint, $prefix = '', array $options = [])
	{
		$this->ossClient = $client;
		$this->bucket = $bucket;
		$this->setPathPrefix($prefix);
		$this->endpoint = $endpoint;
		$this->options = $options;
	}

	/**
	 *	Write a new file.
	 *
	 *	@param		string		$path
	 *	@param		string		$resource
	 *	@param		Config		$config
	 *
	 *	@return		bool|array		false on failure file meta data on success
	 */
	public function write($path, $resource, Config $config)
	{
		return $this->upload($path, $resource, $config);
	}

	/**
	 *	Update a file.
	 *
	 *	@param		string		$path
	 *	@param		string		$contents
	 *	@param		Config		$config		Config object
	 *
	 *	@return		bool|array		false on failure file meta data on success
	 */
	public function update($path, $contents, Config $config)
	{
		$this->delete($path);

		return $this->write($path, $contents, $config);
	}

	/**
	 *	Rename a file.
	 *
	 *	@param		string		$path
	 *	@param		string		$newpath
	 *
	 *	@return		bool
	 */
	public function rename($path, $newpath)
	{
		if ( ! $this->copy($path, $newpath) )
		{
			return false;
		}

		return $this->delete($path);
	}

	/**
	 *	Delete a file.
	 *
	 *	@param		string		$path
	 *
	 *	@return		bool
	 */
	public function delete($path)
	{
		$this->ossClient->deleteObject($this->bucket, $this->applyPathPrefix($path));

		return ! $this->has($path);
	}

	/**
	 *	Delete a directory.
	 *
	 *	@param		string		$dirname
	 *
	 *	@return		bool
	 */
	public function deleteDir($dirname)
	{
		$key = $this->applyPathPrefix($dirname);

		try {
			$this->ossClient->deleteObject($this->bucket, $key);

		}
		catch (OssException $e) {

			return false;
		}

		return true;
	}

	/**
	 *	Create a directory.
	 *
	 *	@param		string		$dirname		directory name
	 *	@param		Config		$config
	 *
	 *	@return		bool|array
	 */
	public function createDir($dirname, Config $config)
	{
		$key = $this->applyPathPrefix($dirname);

		$options = $this->getOptionsFromConfig($config);

		try {
			$this->ossClient->createObjectDir(
				$this->bucket,
				$key
			);

		}
		catch (OssException $e) {

			return false;
		}

		$result = $this->normalizeResponse($options, $key);

		$result['type'] = 'dir';

		return $result;
	}

	/**
	 *	Check whether a file exists.
	 *
	 *	@param		string		$path
	 *
	 *	@return		bool
	 */
	public function has($path)
	{
		$is = function(string $pattern, string $value) : bool
		{
			if ( $pattern == $value )
			{
				return true;
			}

			$pattern = str_replace(
				'\*',
				'.*',
				preg_quote($pattern, '#')
			);

			return (bool) preg_match('#^'.$pattern.'\z#u', $value);
		};

		$path = $is('*.*', $path) ? $path : $path . '/';

		return $this->ossClient->doesObjectExist(
			$this->bucket,
			$this->applyPathPrefix($path)
		);
	}

	/**
	 *	Read a file.
	 *
	 *	@param		string		$path
	 *
	 *	@return		bool|array
	 */
	public function read($path)
	{
		$response = $this->readObject($path);

		if ( $response !== false )
		{
			$response['contents'] = $response['contents']->getContents();
		}

		return $response;
	}

	/**
	 *	List contents of a directory.
	 *
	 *	@param		string		$directory
	 *	@param		bool		$recursive
	 *
	 *	@return		array
	 */
	public function listContents($directory = '', $recursive = false)
	{
		$dirObjects = $this->listDirObjects($directory, true);
		$contents = $dirObjects["objects"];

		$result = array_map([$this, 'normalizeResponse'], $contents);
		$result = array_filter($result, function ($value) {
			return $value['path'] !== false;
		});

		return Util::emulateDirectories($result);
	}

	/**
	 *	@param		array		$options
	 *
	 *	@return		array
	 */
	protected function retrievePaginatedListing(array $options)
	{
		$resultPaginator = $this->ossClient->listObjects($this->bucket);
		$listing = [];

		foreach ( $resultPaginator as $result )
		{
			$listing = array_merge(
				$listing,
				$result->get('Contents') ?: [],
				$result->get('CommonPrefixes') ?: []
			);
		}

		return $listing;
	}

	/**
	 *	Get all the meta data of a file or directory.
	 *
	 *	@param		string		$path
	 *
	 *	@return		bool|array
	 */
	public function getMetadata($path)
	{
		try {
			$result = $this->ossClient->getObjectMeta($this->bucket, $this->applyPathPrefix($path));
		}
		catch (OssException $exception) {

			return false;
		}

		return $this->normalizeResponse($result, $path);
	}

	/**
	 *	Get all the meta data of a file or directory.
	 *
	 *	@param		string		$path
	 *
	 *	@return		array
	 */
	public function getSize($path)
	{
		$response = $this->ossClient->getObjectMeta($this->bucket, $this->applyPathPrefix($path));
        return [
            'size' => $response['content-length']
        ];
	}

	/**
	 *	Get the mimetype of a file.
	 *
	 *	@param		string		$path
	 *
	 *	@return		array
	 */
	public function getMimetype($path)
	{
		$response = $this->ossClient->getObjectMeta($this->bucket, $this->applyPathPrefix($path));

        return [
            'mimetype' => $response['content-type']
        ];
	}

	/**
	 *	Get the timestamp of a file.
	 *
	 *	@param		string		$path
	 *
	 *	@return		false|array
	 */
	public function getTimestamp($path)
	{
		$response = $this->getMetadata($path);

		return [
			'timestamp'		=> strtotime($response['last-modified'])
		];
	}

	/**
	 *	Write a new file using a stream.
	 *
	 *	@param		string			$path
	 *	@param		resource		$resource
	 *	@param		Config  		$config		Config object
	 *
	 *	@return		array|false false on failure file meta data on success
	 */
	public function writeStream($path, $resource, Config $config)
	{
		$options = $this->getOptionsFromConfig($this->options, $config);
		$contents = stream_get_contents($resource);

		return $this->write($path, $contents, $config);
	}

	 /**
	 *	Upload an object.
	 *
	 *	@param		$path
	 *	@param		$body
	 *	@param		Config		$config
	 *
	 *	@return		array
	 */
	protected function upload($path, $body, Config $config)
	{
		$key = $this->applyPathPrefix($path);
		$options = $this->getOptionsFromConfig($config);

		if ( !isset($options[OssClient::OSS_CONTENT_TYPE]) && is_string($body) )
		{
			$options[OssClient::OSS_CONTENT_TYPE] = Util::guessMimeType($path, $body);
		}

		if ( !isset($options[OssClient::OSS_CONTENT_LENGTH]) )
		{
			$options[OssClient::OSS_CONTENT_LENGTH] = is_string($body) ? Util::contentSize($body) : Util::getStreamSize($body);
		}

		$this->ossClient->putObject($this->bucket, $key, $body, $options);

		return $this->normalizeResponse($options, $key);
	}

	/**
	 *	Update a file using a stream.
	 *
	 *	@param		string		$path
	 *	@param		resource	$resource
	 *	@param		Config		$config		Config object
	 *
	 *	@return		array|bool		false on failure file meta data on success
	 */
	public function updateStream($path, $resource, Config $config)
	{
		$contents = stream_get_contents($resource);
		return $this->upload($path, $contents, $config);
	}

	/**
	 *	Copy a file.
	 *
	 *	@param		string		$path
	 *	@param		string		$newpath
	 *
	 *	@return		bool
	 */
	public function copy($path, $newpath)
	{
		$object = $this->applyPathPrefix($path);
		$newObject = $this->applyPathPrefix($newpath);
		try{
			$this->client->copyObject(
				$this->bucket,
				$object,
				urlencode($this->bucket . '/' . $this->applyPathPrefix($path)),
				$newObject
			);

			return true;
		}
		catch (OssException $e) {
			return false;
		}

	}

	/**
	 *	Read a file as a stream.
	 *
	 *	@param		string		$path
	 *
	 *	@return		array|false
	 */
	public function readStream($path)
	{
		$result = $this->readObject($path);
		$result['stream'] = $result['raw_contents'];
		rewind($result['stream']);

		//	Ensure the EntityBody object destruction doesn't close the stream
		$result['raw_contents']->detachStream();
		unset($result['raw_contents']);

		return $result;
	}

	/**
	 *	Read an object and normalize the response.
	 *
	 *	@param		$path
	 *
	 *	@return		array|bool
	 */
	protected function readObject($path)
	{
		$object = $this->applyPathPrefix($path);

		$result['body'] = $this->ossClient->getObject($this->bucket, $object);
		$result = array_merge($result, ['type' => 'file']);

		return $this->normalizeResponse($result, $path);
	}

	/**
	 *	Set the visibility for a file.
	 *
	 *	@param		string		$path
	 *	@param		string		$visibility
	 *
	 *	@return		array|false file meta data
	 */
	public function setVisibility($path, $visibility)
	{
		try {
			$this->ossClient->putObjectAcl(
				$this->bucket,
				$this->applyPathPrefix($path),
				$visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private'
			);
		}
		catch (OssException $exception) {
			return false;
		}

		return compact('path', 'visibility');
	}

	/**
	 *	Get the visibility of a file.
	 *
	 *	@param		string		$path
	 *
	 *	@return		array
	 */
	public function getVisibility($path)
	{
		return [
			'visibility' => $this->ossClient->getObjectAcl($this->bucket, $this->applyPathPrefix($path))
		];
	}

	/**
	 *	Get the oss disk request url.
	 *
	 *	@param		string			$path
	 *
	 *	@return		string
	 */
	public function getUrl(string $path)
	{
		return $path . '/';
	}

	/**
	 *	The the ACL visibility.
	 *
	 *	@param		string		$path
	 *
	 *	@return		string
	 */
	protected function getObjectACL($path)
	{
		$metadata = $this->getVisibility($path);

		return $metadata['visibility'] === AdapterInterface::VISIBILITY_PUBLIC ?
			OssClient::OSS_ACL_TYPE_PUBLIC_READ :
			OssClient::OSS_ACL_TYPE_PRIVATE;
	}

	public function applyPathPrefix($prefix)
	{
		return ltrim(parent::applyPathPrefix($prefix), '/');
	}

	public function setPathPrefix($prefix)
	{
		return parent::setPathPrefix(ltrim($prefix, '/'));
	}

	/**
	 *	Get the OssClient instance.
	 *
	 *	@return		object
	 */
	public function getAdapterClient()
	{
		return $this->ossClient;
	}

	/**
	 *	Get the sign url.
	 *
	 * @param  string  $bucket
	 * @param  string  $object
	 * @param  int  $timeout
	 * @param  arrays  $options
	 *
	 * @return string
	 */
	public function getSignUrl($bucket, $object, $timeout = 60, array $options = [])
	{
		$resuts = [
			OssClient::OSS_PROCESS => sprintf(
				'image/resize,m_pad,h_%d,w_%d,color_FFFFFF',
				$options['height'] ?? 0,
				$options['width'] ?? 0
			)
		];

		return $this
			->getAdapterClient()
			->signUrl($bucket, $object, $timeout, 'GET', $resuts);
	}

	/**
	 *	Get the bucket.
	 *
	 *	@return		string
	 */
	public function getBucket() : string
	{
		return $this->bucket;
	}

	/**
	 *	Get options from the config.
	 *
	 *	@param		Config $config
	 *
	 *	@return		array
	 */
	protected function getOptionsFromConfig(Config $config)
	{
		$options = $this->options;

		if ( $visibility = $config->get('visibility') )
		{
			// For local reference
			$options['visibility'] = $visibility;
			// For external reference
			$options['x-oss-object-acl'] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private';
		}

		if ( $mimetype = $config->get('mimetype') )
		{
			// For local reference
			$options['mimetype'] = $mimetype;
			// For external reference
			$options['ContentType'] = $mimetype;
		}

		foreach ( static::$metaOptions as $option )
		{
			if ( ! $config->has($option) )
			{
				continue;
			}
			$options[$option] = $config->get($option);
		}

		return $options;
	}

	/**
	 *	Normalize the object result array.
	 *
	 *	@param		array  $object
	 *	@param		string		$path
	 *
	 *	@return		array
	 */
	protected function normalizeResponse(array $response, $path = null)
	{
		$result = [
			'path' => $path ?: $this->removePathPrefix(
				$response['Key'] ?? $response['Prefix']
			),
		];
		$result = array_merge($result, Util::pathinfo($result['path']));

		if ( isset($response['LastModified']) )
		{
			$result['timestamp'] = strtotime($response['LastModified']);
		}

		if ( substr($result['path'], -1) === '/' )
		{
			$result['type'] = 'dir';
			$result['path'] = rtrim($result['path'], '/');

			return $result;
		}

		return array_merge(
			$result,
			Util::map($response, static::$resultMap),
			[
				'type' => 'file'
			]
		);
	}
}