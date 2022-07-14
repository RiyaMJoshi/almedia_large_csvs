<?php

namespace App\Controller;

use App\Entity\MetaTable;
use App\Repository\MetaTableRepository;
use Aws\Credentials\Credentials;
use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Aws\S3\Exception\S3Exception;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use ZipArchive;
use League\Csv\Reader;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Writer;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File;
// use PhpParser\Node\Scalar\MagicConst\File;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Aws\S3\S3Client;

class FileUploadController extends AbstractController
{
    // Get Credentials
    private $credentials;
    private $config;
    public function __construct()
    {
        $this->credentials = new Credentials($_ENV['AWS_S3_ACCESS_ID'], $_ENV['AWS_S3_ACCESS_SECRET']);
        $this->config = array(
            'version' => $_ENV['AWS_S3_VERSION'],
            'region' => $_ENV['AWS_S3_REGION'],
            'credentials' => $this->credentials
        );
    }
     
    // Home Page
    /**
     * @Route("/", name="app_homepage")
     */
    function index(Request $request): Response
    {
        $error = "";
        if ($request->get('error')) {
            $error = trim($request->get('error'), '"');
        }
        return $this->render('file_upload/index.html.twig', [
            'controller_name' => 'FileUploadController',
            'invalid_format' => $error
        ]);
    }

    // Get File from User and Upload it to the S3 (Temporarily to Uploads directory if it's zip)
    /**
     * @Route("/upload", name="app_upload_file")
     */
    function upload(Request $request, ManagerRegistry $doctrine, EntityManagerInterface $entityManager)
    {
      
        $entityManager = $doctrine->getManager(); 
        //Get and Upload CSV
        $file = $request->files->get('formFile');
        // dd($file);

        // Get MIME Type of Uploaded File
        $mime = $_FILES['formFile']['type'];
        // dd($mime);

        // Guess File Extension based on File Content
        $originalExtension = $file->guessExtension();
        // dd($originalExtension);

        // Get Original FileName with Extension
        $originalFile = pathinfo($file->getClientOriginalName(), PATHINFO_BASENAME);
        
        // Get Original FileName without Extension
        $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        // Get Extension of Original File
        $userExtension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        
        $uploads_directory = $this->getParameter('uploads_directory');
        $random_num = md5(uniqid());
        $fileKeyS3 = $random_num . '.' . $userExtension;
        $filename = '';
        $filesize = 0;

        // Upload File to S3 Bucket
        $s3 = new S3Client($this->config);

        // Extract file if it is zip
        if ($originalExtension == 'zip') {
            $zipArchive = new ZipArchive();
            $zipArchive->open($file);
            // Count Total Files inside Zip
            $zipFileCounts = $zipArchive->count();
            // Get first file inside zip
            $stat = $zipArchive->statIndex(0);

            // file1 = Basename = Filename (inside zip) with Extension (string)
            $file1 = basename($stat['name']);
            // Extension of file inside the zip file (It must be 'csv' for further process)
            $fileExt = '.' . pathinfo($stat['name'], PATHINFO_EXTENSION);
            // dd($fileExt);
            
            // CSV Filename (for Local) after renaming (string)
            $filename = $random_num . $fileExt; 
            
            // Extract Zip to Local Uploads, Rename it, Upload CSV in it to S3 and Delete from Local Uploads
            $zipArchive->extractTo($uploads_directory, $file1);
            $zipArchive->close();
            $local_file_full = $uploads_directory . '/' . $filename;
            rename($uploads_directory."/".$file1, $local_file_full);
            $filesize = filesize($local_file_full); // bytes
            $mime = mime_content_type($local_file_full); 
            // dd($mime);
            // Check if File inside zip is in CSV format; Redirect if Not Supported
            $not_supported = $this->checkZipContents($zipFileCounts, $fileExt, $mime);
            // dd($not_supported);
            
            if ($not_supported) {
                return $this->render('file_upload/index.html.twig', [
                    'controller_name' => 'FileUploadController',
                    'invalid_format' => $not_supported
                ]);
            }
                       
            try {
                // Upload CSV File from Local to S3 Bucket
                // $this->uploadToS3($s3, $filename, $local_file_full);

                // Upload ZIP to S3 for Time Optimisation
                $this->uploadToS3($s3, $fileKeyS3, $file);

                // Get Column Names from CSV
                $columns = $this->getColumnHeaders($local_file_full);
                // dd($columns);
                // Delete file from uploads directory
                $fileSystem = new Filesystem();
                $fileSystem->remove($local_file_full);
            } catch (S3Exception $e) {
                echo $e->getMessage() . "\n";
            }
        } 
        // Move directly if it is CSV
        else if ($originalExtension == 'csv' || ($originalExtension == 'txt' && $mime == "text/csv")) {
            // Filename after renaming (string)
            // $filename = $random_num . '.' . $file->guessExtension();
            $filesize = filesize($file); // bytes
          
            try {
                // Upload File to S3 Bucket
                $this->uploadToS3($s3, $fileKeyS3, $file);

                $s3->registerStreamWrapper();
                $url = 's3://' . $_ENV['AWS_S3_BUCKET_NAME'] . '/' .$fileKeyS3;

                // Get Column Names from S3 CSV
                $columns = $this->getColumnHeaders($url);
                // dd($columns);
            } catch (S3Exception $e) {
                echo $e->getMessage() . "\n";
            }
        }
        else {
            $not_supported = "There is something wrong with the file content. Please check your file!";
            return $this->render('file_upload/index.html.twig', [
                'controller_name' => 'FileUploadController',
                'invalid_format' => $not_supported
            ]);
        }
        // dd("Zip uploaded");
        // die();
        // Set filename as session
        $session = new Session();
        // //$session->start();
        $session->set('user_id', $random_num);
        // $local_file_full = Absolute file path
        // $local_file_full = $uploads_directory . '/' . $filename;
        // $filesize = filesize($local_file_full); // bytes
        $filesize = round($filesize / 1024, 2); // Convert Byte size to KB

    
        // Save to meta_table in db

        $metaTable = new MetaTable();
        $em = $doctrine->getManager();
        $metaTable->setFilename($fileKeyS3);
        $metaTable->setFilesize($filesize);
        $metaTable->setColumns($columns);
        $metaTable->setOriginalFileName($originalFileName);
        $em->persist($metaTable);
        $em->flush();

        return $this->redirectToRoute('app_modify_file', [
            'filename' => (string) $fileKeyS3,
        ]);
    } 

    // Fetch Column Names from Database to manipulate further
    /**
     * @Route("/modify", name="app_modify_file")
     */
    public function modify(Request $request, MetaTableRepository $metaTableRepository): Response
    {   
        $filename = $request->get('filename');

        $result = $metaTableRepository->getColumnNames($filename);
        $columns = $result[0]['columns'];
       
        return $this->render('file_upload/modify.html.twig', [
           'columns' => $columns,
            'filename' => $filename,
        ]);

    }

    // Export the modified CSV
    /**
     * @Route("/export/{format<zip|csv>}", name="app_export")
     */
    public function export($format, Request $request, EntityManagerInterface $entityManager): Response
    {      
        ob_start();
        $uploads_directory = $this->getParameter('uploads_directory');
        $filename = $request->get('filename');
        $local_file_full = $uploads_directory . '/' . $filename;

        // Modified Index wise Columns
        $original_column = $request->get('original_cols');
        // dump($original_column);
        $renamed_column = $request->get('text');
        // dump($renamed_column);

        $cols = array();
        $cols['ResponseFormat'] = $format;
        $cols['Records'] = array(
                                'BucketName' => $_ENV['AWS_S3_BUCKET_NAME'],
                                'ObjectKey'    => $filename
                            );
                            
        $cols['Data'] = array();
        for ($i=0; $i < count($original_column); $i++) { 
            array_push(
                $cols['Data'], 
                array('Order' => $i+1, 'OldColumn' => $original_column[$i], 'NewColumn' => $renamed_column[$i])
            );    
        }
        $json_cols = json_encode($cols);
        // dd($cols);
        // dd($json_cols);

        try {
            $lambdaClient = new LambdaClient($this->config);

            $result = $lambdaClient->invoke([
                'FunctionName' => 'arn:aws:lambda:us-east-1:811490560759:function:newCSVEditor',
                'Payload' => $json_cols
            ]);
            $response = json_decode($result->get('Payload')->__toString(), true);
            // dump($response);
            // die();

            if (array_key_exists('statusCode', $response)) {
                if ($response['statusCode'] == 200) {
                    $download_url_json = $response['body'];
                    $download_url = json_decode($download_url_json, true)['url'];
                    // dump($download_url);
                    $download_filename = explode($_ENV['AWS_S3_BUCKET_NAME'].'/', $download_url)[1];
                    // dump($download_filename);
                }
                else if ($response['statusCode'] == 404) {
                    $error = $response['error'];
                    return $this->redirectToRoute('app_homepage', [
                        'error' => $error
                    ]);
                    // dd($error);
                }
            }
            else if (array_key_exists('errorType', $response)) {
                if ($response['errorType'] == "UnicodeDecodeError") {
                    $error = "Some unsupported character found in your File.. Could not process further!";
                    return $this->redirectToRoute('app_homepage', [
                        'error' => $error
                    ]);
                    // dd($error);
                }
            }
            
        } catch (LambdaException $e) {
            echo $e->getMessage() . "\n";
        }
        
        
            $metaRecord = $entityManager->getRepository(MetaTable::class)->findOneBy(['filename' => $filename]);
            $originalFileName = $metaRecord->getOriginalFileName();
            $convertedFileName = "converted_" . $originalFileName . ".$format";
        
            $session = $request->getSession();
            $session->invalidate();
            
            $s3 = new S3Client($this->config);
            try {
                // Get the object.
                $result = $s3->getObject([
                    'Bucket' => $_ENV['AWS_S3_BUCKET_NAME'],
                    'Key'    => $download_filename
                ]);
                // dd($result);
                
                // Display the object in the browser. (Download)
                header("Content-Type: {$result['ContentType']}");
                header("Content-Disposition: attachment; filename=".$convertedFileName);
                return new Response($result['Body']);
                // echo $result['Body'];
            } catch (S3Exception $e) {
                echo $e->getMessage() . PHP_EOL;
            }
            // die();
                        
        //     // $response->headers->set('Location', 'file_upload/index.html.twig');
        //     // header('Location : /file_upload');
            // return $response;
            ob_clean();
    }

    // Upload File to S3 Bucket
    public function uploadToS3(S3Client $s3, $filename_key, $file)
    {
        $s3->putObject([
            'Bucket' => $_ENV['AWS_S3_BUCKET_NAME'],
            'Key'    => $filename_key,
            'Body'   => fopen($file, 'r')
        ]);
        // echo "File Uploaded to S3";
        // return 1;
    }

    // Check if Zip has only a single file and it is a CSV
    public function checkZipContents($zipFileCounts, $fileExt, $mime)
    {
        $not_supported = null;
        if ($zipFileCounts > 1) {
            $not_supported = "Your Zip should contain only a single CSV in it!";
        }
        else if ($fileExt == ".csv" && ($mime == "text/csv" || $mime == "text/plain")) {
            $not_supported = null;
        }
        else {
            $not_supported = "File format other than CSV found in your Zip!";
        }
        return $not_supported;
    }

    // Get Column Names from CSV File
    public function getColumnHeaders($url)
    {
        // Read CSV with fopen
        if (($handle = fopen($url, "rb")) !== false) {
            $columns = fgetcsv($handle, 3000, ",");
            // dump($columns);
            fclose($handle);
            return $columns;
        }
    }
       
}







