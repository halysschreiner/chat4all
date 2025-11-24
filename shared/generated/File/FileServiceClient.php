<?php
// GENERATED CODE -- DO NOT EDIT!

namespace File;

/**
 * Serviço de Gerenciamento de Arquivos
 */
class FileServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Iniciar upload de arquivo (multipart)
     * @param \File\InitiateUploadRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function InitiateUpload(\File\InitiateUploadRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/file.FileService/InitiateUpload',
        $argument,
        ['\File\InitiateUploadResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Upload de parte do arquivo
     * @param \File\UploadPartRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function UploadPart(\File\UploadPartRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/file.FileService/UploadPart',
        $argument,
        ['\File\UploadPartResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Completar upload multipart
     * @param \File\CompleteUploadRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function CompleteUpload(\File\CompleteUploadRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/file.FileService/CompleteUpload',
        $argument,
        ['\File\CompleteUploadResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Cancelar upload
     * @param \File\AbortUploadRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function AbortUpload(\File\AbortUploadRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/file.FileService/AbortUpload',
        $argument,
        ['\File\AbortUploadResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Obter informações do arquivo
     * @param \File\GetFileInfoRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetFileInfo(\File\GetFileInfoRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/file.FileService/GetFileInfo',
        $argument,
        ['\File\GetFileInfoResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Gerar URL de download temporária
     * @param \File\GetDownloadUrlRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetDownloadUrl(\File\GetDownloadUrlRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/file.FileService/GetDownloadUrl',
        $argument,
        ['\File\GetDownloadUrlResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Listar arquivos de uma conversa
     * @param \File\ListFilesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ListFiles(\File\ListFilesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/file.FileService/ListFiles',
        $argument,
        ['\File\ListFilesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Deletar arquivo
     * @param \File\DeleteFileRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function DeleteFile(\File\DeleteFileRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/file.FileService/DeleteFile',
        $argument,
        ['\File\DeleteFileResponse', 'decode'],
        $metadata, $options);
    }

}
