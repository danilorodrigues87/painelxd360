<?php 

namespace App\Utils;

class View {

	//VARIAVEIS PADRÕES DA VIEW
	private static $vars = [];

	//DEFINE DADOS INICIAIS DA CLASSE
	public static function init($vars = []){
		self::$vars = $vars;
	}

	//metodo que retorna o conteudo de uma view
	private static function getContentView($view){
		$file = __DIR__.'/../../resources/view/'.$view.'.html';
		return file_exists($file) ? file_get_contents($file) : '';
	}

	//metodo que retorna o conteudo rederizado para uma view
	//string e array ou numnericos
	 public static function render($view, $vars = []){
	 	//CONTEÚDO DA VIEW
	 	$contentView = self::getContentView($view);

	 	//MERGE DE VARIAVEIS DA VIEW
	 	$vars = array_merge(self::$vars,$vars);

	 	//CHAVES DO ARRAY DE VARIAVEIS
	 	$keys = array_keys($vars);
	 	$keys = array_map(function($item){
	 		return '{{'.$item.'}}';
	 	},$keys);

	 	//RETORNA O CONTEUDO RENDERIZADO
	 	return str_replace($keys, array_values($vars), $contentView);

	 } 
}