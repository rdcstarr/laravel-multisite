# Laravel Multisite

[![Latest Version on Packagist](https://img.shields.io/packagist/v/rdcstarr/laravel-multisite.svg?style=flat-square)](https://packagist.org/packages/rdcstarr/laravel-multisite)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/rdcstarr/laravel-multisite/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/rdcstarr/laravel-multisite/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/rdcstarr/laravel-multisite/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/rdcstarr/laravel-multisite/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/rdcstarr/laravel-multisite.svg?style=flat-square)](https://packagist.org/packages/rdcstarr/laravel-multisite)

> A powerful and flexible Laravel package for multisite management, each site with different database and .env.

## ✨ Features

- 🧩 **Multisite** - Manage multiple sites with separate databases and configurations.

## 📦 Installation

Install the package via Composer:

```bash
composer require rdcstarr/laravel-multisite
```
1. Prepare your Nginx or Apache configuration to route requests to the same Laravel application. Example for nginx:
	```properties
	server {
		listen 80;
		server_name example.com;

		root /home/admin/web/{core.local}/public;

		index index.php index.html index.htm;

		location / {
			try_files $uri $uri/ /index.php?$query_string;
		}

		location ~ \.php$ {
			include snippets/fastcgi-php.conf;
			fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
			fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
			include fastcgi_params;
		}
	}
	```
2. Replace `use Illuminate\Foundation\Application` with `use Rdcstarr\Multisite\Foundation\Application` in `./bootstrap/app.php`.
3. Add next cod before bootstrap application:
	```php
	$_SERVER['MULTISITE'] = [
		'production'      => [
			'sites_path'  => '/home/admin/web', // path where all sites are located
			'base_path'   => '/private',        // path to the folder with .env files, e.g. in my case path will be: `/home/admin/web/{site.tld}/private/.env`
			'public_path' => '/public_html',    // path to the public files, e.g. in my case path will be: `/home/admin/web/{site.tld}/public_html`
		],
		'local'           => [
			'sites_path'  => '/var/www/html/.subdomains', // path where all sites are located in local development
			'base_path'   => '/private',                  // path to the folder with .env files, e.g. in my case path will be: `/var/www/html/.subdomains/{site.tld}/private/.env`
			'public_path' => '/public_html',             // path to the public files, e.g. in my case path will be: `/var/www/html/.subdomains/{site.tld}/public_html`
		],
		// List of artisan commands who doesn't require multisite context
		'SKIP_VALIDATION' => [
			'theme',
		],
	];
	```

## 🛠️ Artisan Commands

The package provides dedicated Artisan commands for managing settings directly from the command line:

#### List sites
```bash
php artisan multisite:list
```

#### Run migrations for all sites
```bash
php artisan multisite:migrate [--force] [--seed] [--seeder]
```

#### Run migrations fresh for all sites
```bash
php artisan multisite:migrate-fresh [--force] [--seed] [--seeder]
```

#### Queue worker for sites
```bash
php artisan multisite:queue
```

#### Scheduler run for all sites
```bash
php artisan multisite:schedule-run
```

#### Seed database for all sites
```bash
php artisan multisite:seed [--force] [--class]
```

## 🧪 Testing
```bash
composer test
```

## 📖 Resources
 - [Changelog](CHANGELOG.md) for more information on what has changed recently. ✍️

## 👥 Credits
 - [Rdcstarr](https://github.com/rdcstarr) 🙌

## 📜 License
 - [License](LICENSE.md) for more information. ⚖️

## ToDo
- [] Change single and daily logs to base path like `/home/admin/web/{site.tld}/private/logs/laravel.log`
