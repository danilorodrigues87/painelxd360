<?php 

namespace App\Common;

class Upload{

    private $name; // string
    private $extension; // sem ponto
    private $type; // tipo de arquivo
    private $tmpName; // caminho temporario
    private $error; // codigo de erro
    private $size; // tamanho do arquivo
    private $duplicates=0;
    private $caminho_absoluto = __DIR__.'/../../uploads';

    public function __construct($file){
        $this->type = $file['type'];
        $this->tmpName = $file['tmp_name'];
        $this->error = $file['error'];
        $this->size = $file['size'];

        $info = pathinfo($file['name']);
        $this->name = $info['filename'];
        $this->extension = $info['extension'];
    }

    // ALTERAR O NOME DO ARQUIVO
    public function setName($name){
    	$this->name = $name;
    }

    // GERA UM NOME ALEATORIO PARA O ARQUIVO
    public function generateNewName(){

    	$this->name = time().'-'.rand(100000,999999).'-'.uniqid();
    }

    // RETORNA O NOME DO ARQUIVO E EXTENSÃO
    public function getBasename(){
        $extension = strlen($this->extension) ? '.'.$this->extension : '';

        // valida duplicação
        $duplicates = $this->duplicates > 0 ? '-'.$this->duplicates : '';
        return $this->name.$duplicates.$extension;
    }

    private function getPossibleBasename($dir,$overwrite){

    	// sobrescrever arquivo
    	if($overwrite) return $this->getBasename();

    	// não pode sobrescrever arquivo
    	$basename = $this->getBasename();

    	// verifica duplicação
    	if(!file_exists($dir.'/'.$basename)){
    		return $basename;
    	}

    	// incrementa duplicações
    	$this->duplicates++;

    	return $this->getPossibleBasename($dir,$overwrite);

    }

    // MOVE O ARQUIVO DE UPLOAD
    public function upload($dir_file, $overwrite = true, $imgAntiga = null){

    	// junta o caminho absoluto a pasta de destino
    	$dir = $this->caminho_absoluto.$dir_file;

        // Verifica erro
        if($this->error != 0) return false;

        // Verifica se o diretório existe, caso contrário, cria
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Exclui a imagem antiga, se houver
        if($imgAntiga && file_exists($dir . '/' . $imgAntiga)){
            unlink($dir . '/' . $imgAntiga);
        }

        // Caminho completo no sistema de arquivos
        $path = $dir . '/' . $this->getPossibleBasename($dir,$overwrite);

        // Move o arquivo para a pasta de destino
        return move_uploaded_file($this->tmpName, $path);
    }


    // ENVIA MULTIPLO ARQUIVOS
    public static function creatMultiUploads($files){
    	$uploads = [];

    	foreach($files['name'] as $key=>$value){
    		$file = [
    			'name' => $files['name'][$key],
    			'type' => $files['type'][$key],
    			'tmp_name' => $files['tmp_name'][$key],
    			'error' => $files['error'][$key],
    			'size' => $files['size'][$key]
    		];
    	}

    	// nova instancia
    	$uploads[] = new Upload($file);

    }


}
