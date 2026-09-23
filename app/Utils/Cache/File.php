<?php

namespace App\Utils\Cache;

class File{

    //RETORNA O CAMINHO ATÉ O ARQUIVO DE CACHE
    private static function getFilePath($hash){
        $dir = getenv('CACHE_DIR');

        //VERIFICA A EXISTENCIA DO DIRETORIO
        if(!file_exists($dir)){
            mkdir($dir,0755,true);
        }

        //RETORNA O CAMINHO ATÉ O ARQUIVO
        return $dir.'/'.$hash;
    }

    //GUARDA INFORMAÇÕES NO CACHE
    private static function storageCache($hash, $content){
        //SERIALIZA O RETORNO
        $serializedData = serialize($content);
        
        //OBTÉM O CAMINHO ATÉ O ARQUIVO DE CACHE
        $cacheFile = self::getFilePath($hash);
        
        //GRAVA AS INFORMAÇÕES NO ARQUIVO
        file_put_contents($cacheFile, $serializedData);
    }

    // RESPONSÁVEL POR RETORNAR O CONTEÚDO GRAVADO NO CACHE
    private static function getContentCache($hash, $expiration) {
        // OBTÉM O CAMINHO DO ARQUIVO
        $cacheFile = self::getFilePath($hash);

        // VERIFICA SE O ARQUIVO EXISTE
        if (!file_exists($cacheFile)) {
            return false;
        }

        // VALIDA A EXPIRAÇÃO DO CACHE
        $modificationTime = filemtime($cacheFile);
        if ($modificationTime === false) {
            return false;
        }

        $diffTime = time() - $modificationTime;

        if ($diffTime > $expiration) {
            return false;
        } 

        // RETORNA O DADO REAL
        $serializedData = file_get_contents($cacheFile);
        if ($serializedData === false) {
            return false;
        }

        return unserialize($serializedData);
    }

    //RESPOMSÁVEL POR OBTER A INFORMAÇÃO DO CACHE
    public static function getCache($hash, $expiration, $function){
        //VERIFICA O CONTEUDO GRAVADO
        if($content = self::getContentCache($hash, $expiration)){
            return $content;
        }

        //EXECUÇÃO DA FUNÇÃO
        $content = $function();

        //GRAVA O RETORNO NO CACHE
        self::storageCache($hash, $content);

        //RETORNA O CONTEUDO
        return $content;
    }
}
