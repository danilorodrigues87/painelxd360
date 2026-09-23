<?php

namespace App\Common\Helpers;

/**
 * Web App Manifest dinâmico (start_url/scope conforme deploy /app ou raiz).
 */
class PwaHelper {

	private static function urlBase(): string {
		return rtrim((string)URL, '/');
	}

	private static function raizProjeto(): ?string {
		$raiz = realpath(__DIR__.'/../../../');
		return $raiz !== false ? $raiz : null;
	}

	private static function arquivoExiste(string $relPath): bool {
		$raiz = self::raizProjeto();
		if ($raiz === null) {
			return false;
		}
		return is_file($raiz.'/'.str_replace('/', DIRECTORY_SEPARATOR, ltrim($relPath, '/')));
	}

	/** URL pública de ícone PWA com fallback para favicon XD360. */
	public static function iconUrl(string $filename, ?string $fallbackRel = null): string {
		$rel = 'resources/pwa/'.$filename;
		if (self::arquivoExiste($rel)) {
			return self::urlBase().'/'.$rel;
		}
		if ($fallbackRel !== null && self::arquivoExiste($fallbackRel)) {
			return self::urlBase().'/'.ltrim($fallbackRel, '/');
		}
		return self::urlBase().'/'.BrandingHelper::ICONE_XD360;
	}

	public static function appleTouchIconUrl(): string {
		return self::iconUrl('icon-192.png', BrandingHelper::ICONE_XD360);
	}

	public static function manifestArray(): array {
		$base = self::urlBase();
		$icon192 = self::iconUrl('icon-192.png', BrandingHelper::ICONE_XD360);
		$icon512 = self::iconUrl('icon-512.png', BrandingHelper::ICONE_XD360);
		$iconMask = self::iconUrl('icon-512-maskable.png', BrandingHelper::ICONE_XD360);

		return [
			'name'             => 'XD360 — Painel',
			'short_name'       => 'XD360',
			'description'      => 'Painel SaaS XD360 — produtos digitais, assinatura e gestão do seu negócio.',
			'start_url'        => $base.'/painel',
			'scope'            => $base.'/',
			'id'               => $base.'/painel',
			'display'          => 'standalone',
			'orientation'      => 'any',
			'background_color' => '#0f172a',
			'theme_color'      => '#6366f1',
			'lang'             => 'pt-BR',
			'categories'       => ['business', 'productivity'],
			'icons'            => [
				[
					'src'     => $icon192,
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				],
				[
					'src'     => $icon512,
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				],
				[
					'src'     => $iconMask,
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				],
			],
		];
	}

	public static function manifestJson(): string {
		$json = json_encode(self::manifestArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		return $json !== false ? $json : '{}';
	}

	public static function serviceWorkerAllowedHeader(): string {
		return self::urlBase().'/';
	}
}
