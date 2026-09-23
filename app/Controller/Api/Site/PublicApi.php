<?php

namespace App\Controller\Api\Site;

use App\Common\Helpers\SiteApiMapper;
use App\Model\Entity\SiteB2bBranding;

class PublicApi {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function branding($request): array {
		return self::respond([
			'branding' => SiteApiMapper::brandingFromEntity(SiteB2bBranding::get()),
			'sqlOk'    => SiteB2bBranding::tabelasExistem(),
		]);
	}
}
