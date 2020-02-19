<?php
//Pull in the secret sauce =)

include_once(dirname(__FILE__) . "/_settings.php");

// Installed From composer.json
require 'vendor/autoload.php';

//for s3
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

//for cloudfront
use Aws\CloudFront\CloudFrontClient; 
use Aws\Exception\AwsException;
// /composer.json




class S3Communicator {
    private $s3Bucket;
    private $s3CloudFrontURL;
    private $s3;
    private $s3CloudFrontDistributionID;

    private $cF;

    //--------------------------------------------------------------------
    // CONSTRUCT
    //--------------------------------------------------------------------
    function __construct($bucketName, $cloudFrontURL, $cloudFrontDistID) {
      $this->s3Bucket = $bucketName;
      $this->s3CloudFrontURL = $cloudFrontURL;
      $this->s3CloudFrontDistributionID = $cloudFrontDistID;

      $this->s3 = new S3Client([
          'version' => 'latest',
          'region'  => S3_REGION,
          'credentials' => [
            'key'    => S3_KEY,
            'secret' => S3_SECRET,
          ]
      ]);

      $this->cF = Aws\CloudFront\CloudFrontClient::factory(array(
        'region' => S3_REGION,
        'version' => 'latest',
        'credentials' => [
          'key'    => S3_KEY,
          'secret' => S3_SECRET
        ]
      ));

    }

    //--------------------------------------------------------------------
    // DESTRUCT
    //--------------------------------------------------------------------
    function __destruct() {
      //
    }

    //--------------------------------------------------------------------
    // CHECK IF FILE EXISTS
    //--------------------------------------------------------------------
    function check_if_exists($theFileName) {
      $response = $this->s3->doesObjectExist($this->s3Bucket, $theFileName);
      return $response;
    }

    //--------------------------------------------------------------------
    // PUT
    //--------------------------------------------------------------------
    function upload_image($formTmpName, $newFileName = null) {
      $milliseconds = round(microtime(true) * 1000);

      //lets detect the REAL image type, not just assume from the filename.
      $myImageExt = $this->derive_image_extension($formTmpName);

      //resize to fit within 300x400, then save as jpg
      $formTmpName = $this->resizeThenConvertImageToJPG($formTmpName, $myImageExt);

      $myFileName = (!$newFileName) ? $milliseconds . ".jpg" : $newFileName;

      $invalidation = null;
      $upload = null;

      //first, lets check if the file exists. if it does, we need to kick off an invalidation after we re-upload this image.
      $fileAlreadyExists = $this->check_if_exists($myFileName);

      try {
        $upload = $this->s3->putObject(
          [
            'Bucket' => $this->s3Bucket,
            'Key' => $myFileName,
            'SourceFile' => $formTmpName,
            'ContentType' => 'image/jpg'
          ]
        );
      } catch (Exception $e) {
        echo 'Caught exception: ',  $e->getMessage(), "\n";
      }

      $invalidation = ($fileAlreadyExists) ? $this->invalidate_image($myFileName) : "new file";

      $finalResponse = array(
        "invalidation" => $invalidation,
        "upload" => $upload
      );

      return $finalResponse;
    }

    //--------------------------------------------------------------------
    // DELETE
    //--------------------------------------------------------------------
    function delete_image($myFileName) {
      //first, lets check if the file exists. if it doesn't, we don't need to delete aything.
      $fileAlreadyExists = $this->check_if_exists($myFileName);

      if ($fileAlreadyExists) {
        try {
          $deleted = $this->s3->deleteObject(
            [
              'Bucket' => $this->s3Bucket,
              'Key' => $myFileName
            ]
          );
        } catch (Exception $e) {
          echo 'Caught exception: ',  $e->getMessage(), "\n";
        }

        $invalidation = $this->invalidate_image($myFileName);

        $finalResponse = array(
          "invalidation" => $invalidation,
          "deleted" => $deleted
        );
      } else {
        $finalResponse = "File does not exist.";
      }


      return $finalResponse;
    }

    //--------------------------------------------------------------------
    // DERIVE CORRECT IMAGE EXTENSION
    //--------------------------------------------------------------------
    function derive_image_extension($formTmpName) {
      $detectedType = exif_imagetype($formTmpName);

      switch ($detectedType) {
        case IMAGETYPE_JPEG:
          $imgExt = ".jpg";
          break;
        case IMAGETYPE_PNG:
          $imgExt = ".png";
          break;
        case IMAGETYPE_GIF:
          $imgExt = ".gif";
          break;
      }

      return $imgExt;
    }

    //--------------------------------------------------------------------
    // CROP IMAGE, THEN SAVE AS JPG
    //--------------------------------------------------------------------
    function resizeThenConvertImageToJPG($formTmpName, $imageType, $crop = false) {

      //might make this dynamic later, but for now lets hard-code 80
      $quality = 80;
      $milliseconds = round(microtime(true) * 1000);
      $outputImage = "/tmp/" . $milliseconds . ".jpg";
      $w = 300;
      $h = 400;

      //get original width and height of image
      list($width, $height) = getimagesize($formTmpName);

      //calc the ratio of the image
      $r = $width / $height;

      //lets recalculate width and height, cropped if we want it, or keeping image ratio (r)
      if ($crop) {
        if ($width > $height) {
          $width = ceil($width-($width*abs($r-$w/$h)));
        } else {
          $height = ceil($height-($height*abs($r-$w/$h)));
        }
        $newwidth = $w;
        $newheight = $h;
      } else {
        if ($w/$h > $r) {
          $newwidth = $h*$r;
          $newheight = $h;
        } else {
          $newheight = $w/$r;
          $newwidth = $w;
        }
      }

      //create a src image object from the original image
      switch ($imageType) {
        case ".jpg":
          $src = imagecreatefromjpeg($formTmpName);
          break;
        case ".png":
          $src = imagecreatefrompng($formTmpName);
          break;
        case ".gif":
          $src = imagecreatefromgif($formTmpName);
          break;
      }

      //create the new image object
      $dst = imagecreatetruecolor($newwidth, $newheight);

      //perform the resize from source to destination
      imagecopyresampled($dst, $src, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);

      //lets grab the exif data of the original file, and fix the rotation of the destination image if neccessary
      //but, this will only work for jpg and gifs.
      if ($imageType == ".jpg" || $imageType == ".gif") {
        $exif = exif_read_data($formTmpName);
        if(!empty($exif['Orientation'])) {
          switch($exif['Orientation']) {
          case 8:
            $dst = imagerotate($dst,90,0);
            break;
          case 3:
            $dst = imagerotate($dst,180,0);
            break;
          case 6:
            $dst = imagerotate($dst,-90,0);
            break;
          } 
        }
      }

      //save it to the tmp file as a jpg
      imagejpeg($dst, $outputImage, $quality);

      //return the tmp file location
      return $outputImage;
    }

    //--------------------------------------------------------------------
    // INVALIDATE IMAGE IN CLOUDFRONT
    //--------------------------------------------------------------------
    function invalidate_image($theFileName) {
      $callerReference = round(microtime(true) * 1000);

      try {
        $result = $this->cF->createInvalidation([
          'DistributionId' => $this->s3CloudFrontDistributionID,
          'InvalidationBatch' => [
            'CallerReference' => $callerReference,
            'Paths' => [
              'Items' => ['/' . $theFileName],
              'Quantity' => 1,
            ],
          ]
        ]);
      } catch (AwsException $e) {
        // output error message if fails
        echo $e->getMessage();
        echo "\n";
      }

     return $result;
    }

    //--------------------------------------------------------------------
    // GET BUCKET NAME
    //--------------------------------------------------------------------
    function get_bucket_name() {
      return $this->s3Bucket;
    }

}
