# flysystem-ali_oss
AliYun OSS Storage adapter for flysystem.

## Installation
```bash
	composer require ofcold/flysystem-ali_oss
```

## Usage

- Laravel Or pyrocms
```php

	use League\Flysystem\Filesystem;
	use League\Flysystem\MountManager;
	use Illuminate\Filesystem\FilesystemManager;

	$driver = new Filesystem(new OssAdapter(
		new OssClient(
			'key',
			'secret',
			'endpoint'
		),
		'bucket',
		'endpoint',
		'Your prefix'
	));

	app(MountManager::class)->mountFilesystem($prefix, $driver);

	app(FilesystemManager::class)->extend(
		$this->disk->getSlug(),
		function () use ($driver) {
			return $driver;
		}
	);
```


## Reference
- [http://flysystem.thephpleague.com/api/](http://flysystem.thephpleague.com/api/)
- [https://github.com/thephpleague/flysystem](https://github.com/thephpleague/flysystem)
- [https://help.aliyun.com/document_detail/32099.html](https://help.aliyun.com/document_detail/32099.html)