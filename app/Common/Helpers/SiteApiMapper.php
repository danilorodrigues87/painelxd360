<?php

namespace App\Common\Helpers;

use App\Model\Entity\SiteB2bBranding;

class SiteApiMapper {

	public static function branding($row): array {
		$r = is_array($row) ? $row : (array)$row;
		return [
			'heroTitulo'           => (string)($r['hero_titulo'] ?? 'Ecossistema completo para escolas profissionalizantes'),
			'heroSubtitulo'        => (string)($r['hero_subtitulo'] ?? ''),
			'heroCtaTexto'         => (string)($r['hero_cta_texto'] ?? 'Solicitar demonstração'),
			'heroCtaLink'          => (string)($r['hero_cta_link'] ?? '/contato'),
			'textoInstitucional'   => (string)($r['texto_institucional'] ?? ''),
			'heroImageUrl'         => BrandingHelper::urlSiteB2bHero($r['hero_image'] ?? null),
			'telefone'             => (string)($r['telefone'] ?? ''),
			'email'                => (string)($r['email'] ?? ''),
			'whatsapp'             => (string)($r['whatsapp'] ?? ''),
			'linkAlunos'           => $r['link_alunos'] ?? null,
			'statEscolas'          => (string)($r['stat_escolas'] ?? '10+'),
			'statAnos'             => (string)($r['stat_anos'] ?? '15+'),
			'statModulos'          => (string)($r['stat_modulos'] ?? '30+'),
			'catalogoCtiEmBreve'   => !empty($r['catalogo_cti_em_breve']),
			'metaTitle'            => (string)($r['meta_title'] ?? 'CTI Educacional — Plataforma para escolas'),
			'metaDescription'      => (string)($r['meta_description'] ?? ''),
			'redesSociais'         => self::redesFromJson($r['redes_sociais_json'] ?? null),
		];
	}

	/** @return array<string,string> */
	private static function redesFromJson(?string $json): array {
		$redes = ConectRedesSociaisHelper::decode($json);
		if ($json === null || trim($json) === '') {
			return $redes;
		}
		$raw = json_decode($json, true);
		if (is_array($raw) && !empty($raw['youtube']) && is_string($raw['youtube'])) {
			$redes['youtube'] = trim($raw['youtube']);
		}
		return $redes;
	}

	public static function brandingFromEntity(SiteB2bBranding $row): array {
		return self::branding((array)$row);
	}
}
