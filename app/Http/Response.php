<?php

namespace App\Http;

class Response{

	private $httpCode = 200; // Código do status HTTP
	private $headers = []; //Cabeçalo do Response
	private $contentType = 'text/html'; //tipo de conteudo
	private $content; //Conteudo do Response

	public function __construct($httpCode,$content,$contentType = 'text/html'){
		$this->httpCode = $httpCode;
		$this->content = $content;
		$this->setContentType($contentType);
		
	}

	//ALTERA O CONTEN TYPE DO RESPONSE
	public function setContentType($contentType){
		$this->contentType = $contentType;
		if ($contentType === 'text/html') {
			$this->addHeader('Content-Type', 'text/html; charset=utf-8');
		} elseif ($contentType === 'application/json') {
			$this->addHeader('Content-Type', 'application/json; charset=utf-8');
		} else {
			$this->addHeader('Content-Type', $contentType);
		}
	}

	//ADICIONA UM REGISTRO NO CABEÇALHO DO RESPONSE
	public function addHeader($key,$value){
		$this->headers[$key] = $value;
	}

	private function sendHeaders(){
		http_response_code($this->httpCode);

		foreach ($this->headers as $key=>$value){
			header($key.': '.$value);
		}
	}

	//ENVIA A RESPOSTA PARA O USUÁRIO
	public function sendResponse(){
		//envia os headers
		$this->sendHeaders();

		//imprime o conteudo
		switch ($this->contentType) {
			case 'text/html':
				echo $this->content;
				exit;
			case 'application/json':
				// Controllers costumam já devolver json_encode(...); não re-encodar string.
				echo is_string($this->content)
					? $this->content
					: json_encode($this->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				exit;
			default:
				echo $this->content;
				exit;
		}
	}


} 
