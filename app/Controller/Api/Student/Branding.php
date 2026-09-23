<?php

namespace App\Controller\Api\Student;

use App\Model\Entity\PortalAlunoBranding;
use App\Common\Helpers\BrandingHelper;

class Branding {

	/** GET público — logo e fundo do login do Ascend. */
	public static function get($request): array {
		$logoUrl = null;
		$loginHeroUrl = null;

		if (PortalAlunoBranding::tabelasExistem()) {
			$row = PortalAlunoBranding::get();
			$logoUrl = BrandingHelper::urlPortalLogo($row->logo ?? null);
			$loginHeroUrl = BrandingHelper::urlPortalLoginHero($row->login_hero ?? null);
		}

		return [
			'code' => 200,
			'json' => json_encode([
				'logoUrl' => $logoUrl,
				'loginHeroUrl' => $loginHeroUrl,
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}
}
