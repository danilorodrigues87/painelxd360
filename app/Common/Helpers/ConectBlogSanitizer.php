<?php

namespace App\Common\Helpers;

class ConectBlogSanitizer {

	private const ALLOWED_TAGS = [
		'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote',
		'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a', 'img', 'figure', 'figcaption',
		'span', 'div', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
	];

	private const IMG_CLASSES = [
		'cj-img-full', 'cj-img-medium', 'cj-img-left', 'cj-img-right', 'cj-img-small',
	];

	public static function html(string $html): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}
		$allowed = '<'.implode('><', self::ALLOWED_TAGS).'>';
		$clean = strip_tags($html, $allowed);
		$clean = preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function (array $m): string {
				return self::sanitizeImgTag($m[0]);
			},
			$clean
		) ?? $clean;
		$clean = preg_replace_callback(
			'/<a\b[^>]*>/i',
			static function (array $m): string {
				return self::sanitizeAnchor($m[0]);
			},
			$clean
		) ?? $clean;
		return $clean;
	}

	public static function textoComentario(string $texto): string {
		$texto = trim(strip_tags($texto));
		return mb_substr($texto, 0, 2000);
	}

	private static function sanitizeImgTag(string $tag): string {
		if (!preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $tag, $m)) {
			return '';
		}
		$src = trim($m[2]);
		if ($src === '' || !self::urlSegura($src)) {
			return '';
		}
		$class = 'cj-img-medium';
		if (preg_match('/\bclass=(["\'])([^"\']*)\1/i', $tag, $cm)) {
			foreach (preg_split('/\s+/', $cm[2]) ?: [] as $c) {
				if (in_array($c, self::IMG_CLASSES, true)) {
					$class = $c;
					break;
				}
			}
		}
		$alt = '';
		if (preg_match('/\balt=(["\'])([^"\']*)\1/i', $tag, $am)) {
			$alt = htmlspecialchars(mb_substr($am[2], 0, 200), ENT_QUOTES, 'UTF-8');
		}
		$srcEsc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
		return '<img src="'.$srcEsc.'" alt="'.$alt.'" class="'.$class.'">';
	}

	private static function sanitizeAnchor(string $tag): string {
		if (!preg_match('/\bhref=(["\'])([^"\']+)\1/i', $tag, $m)) {
			return '<a>';
		}
		$href = trim($m[2]);
		if (!self::urlSegura($href)) {
			return '<a>';
		}
		$hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
		return '<a href="'.$hrefEsc.'" target="_blank" rel="noopener noreferrer">';
	}

	private static function urlSegura(string $url): bool {
		if (str_starts_with($url, '/uploads/img/conect/')) {
			return true;
		}
		if (!preg_match('#^https?://#i', $url)) {
			return false;
		}
		return (bool)filter_var($url, FILTER_VALIDATE_URL);
	}
}
