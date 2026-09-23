<?php

namespace App\Controller\PublicPages;

use App\Common\Helpers\PwaHelper;
use App\Http\Request;

class Pwa {

	public static function manifest(Request $request): string {
		return PwaHelper::manifestJson();
	}

	public static function serviceWorker(Request $request): string {
		$path = realpath(__DIR__.'/../../../sw.js');
		if ($path === false || !is_readable($path)) {
			return '// Service worker não encontrado';
		}
		return (string)file_get_contents($path);
	}

	public static function oneSignalWorker(Request $request): string {
		$path = realpath(__DIR__.'/../../../OneSignalSDKWorker.js');
		if ($path === false || !is_readable($path)) {
			return '// OneSignal worker não encontrado';
		}
		return (string)file_get_contents($path);
	}

	public static function oneSignalUpdaterWorker(Request $request): string {
		$path = realpath(__DIR__.'/../../../OneSignalSDKUpdaterWorker.js');
		if ($path === false || !is_readable($path)) {
			return '// OneSignal updater worker não encontrado';
		}
		return (string)file_get_contents($path);
	}
}
