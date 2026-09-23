<?php

namespace App\Common\Helpers;

use App\Common\Environment;

/**
 * Configuração OneSignal Web Push (SDK + REST).
 */
class OneSignalHelper {

	public static function appId(): string {
		return trim((string)Environment::get('ONESIGNAL_APP_ID', ''));
	}

	public static function restApiKey(): string {
		return trim((string)Environment::get('ONESIGNAL_REST_API_KEY', ''));
	}

	public static function configurado(): bool {
		return self::appId() !== '' && self::restApiKey() !== '';
	}

	public static function sdkHabilitado(): bool {
		return self::appId() !== '';
	}

	/** Caminho relativo ao deploy, sem barra inicial (ex.: app/push/onesignal/OneSignalSDKWorker.js). */
	public static function serviceWorkerPath(): string {
		$prefix = self::urlPathPrefix();
		return ($prefix !== '' ? $prefix.'/' : '').'push/onesignal/OneSignalSDKWorker.js';
	}

	public static function serviceWorkerUpdaterPath(): string {
		$prefix = self::urlPathPrefix();
		return ($prefix !== '' ? $prefix.'/' : '').'push/onesignal/OneSignalSDKUpdaterWorker.js';
	}

	/** Escopo dedicado ao push — evita conflito com o SW do PWA (sw.js). */
	public static function serviceWorkerScope(): string {
		$prefix = self::urlPathPrefix();
		return '/'.($prefix !== '' ? $prefix.'/' : '').'push/onesignal/';
	}

	private static function urlPathPrefix(): string {
		$base = rtrim((string)(defined('URL') ? URL : ''), '/');
		$path = parse_url($base, PHP_URL_PATH);
		if (!is_string($path) || $path === '' || $path === '/') {
			return '';
		}
		return trim($path, '/');
	}

	/**
	 * Tag OneSignal para filtrar push conforme tipo de notificação in-app.
	 */
	public static function tagCanalPorTipo(string $tipo): ?string {
		switch ($tipo) {
			case 'whatsapp_mensagem':
				return 'can_wa';
			case 'meta_messenger':
			case 'meta_instagram':
				return 'can_meta';
			default:
				return null;
		}
	}

	public static function htmlHeadSnippet(): string {
		if (!self::sdkHabilitado()) {
			return '';
		}
		$appId = htmlspecialchars(self::appId(), ENT_QUOTES, 'UTF-8');
		$swPath = htmlspecialchars(self::serviceWorkerPath(), ENT_QUOTES, 'UTF-8');
		$swUpdater = htmlspecialchars(self::serviceWorkerUpdaterPath(), ENT_QUOTES, 'UTF-8');
		$swScope = htmlspecialchars(self::serviceWorkerScope(), ENT_QUOTES, 'UTF-8');
		return '<meta name="onesignal-app-id" content="'.$appId.'">'
			.'<meta name="onesignal-sw-path" content="'.$swPath.'">'
			.'<meta name="onesignal-sw-updater-path" content="'.$swUpdater.'">'
			.'<meta name="onesignal-sw-scope" content="'.$swScope.'">'
			.'<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>';
	}
}
