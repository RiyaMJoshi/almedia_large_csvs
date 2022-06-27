<?php

namespace App\Controller;

use App\Entity\MetaTable;
use App\Repository\MetaTableRepository;
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
use PhpParser\Node\Scalar\MagicConst\File;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Session\Session;
class FileUploadController extends AbstractController
{
    // Home Page
    /**
     * @Route("/", name="app_homepage")
     */
    function index(): Response
    {
        return $this->render('file_upload/index.html.twig', [
            'controller_name' => 'FileUploadController',
        ]);
    }

    // Get File from User and Upload it to the Server (Plus Uploads directory)
    /**
     * @Route("/upload", name="app_upload_file")
     */
    function upload(Request $request, ManagerRegistry $doctrine, EntityManagerInterface $entityManager)
    {
      
        $entityManager = $doctrine->getManager(); 
        //Get and Upload CSV
        $file = $request->files->get('formFile');
        // dd($file);

        // Get Original FileName without Extension
        $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        // Get Extension of Original File
        $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        // dd($extension);
        $uploads_directory = $this->getParameter('uploads_directory');
        $random_num = md5(uniqid());
        $filename = '';
        
        // Extract file if it is zip
        if ($extension == 'zip') {
            $zipArchive = new ZipArchive();
            $zipArchive->open($file);
            $stat = $zipArchive->statIndex(0);

            // file1 = Basename = Filename with Extension (string)
            $file1 = basename($stat['name']);  

            // Extension of file inside the zip file
            $fileExt = '.' . pathinfo($stat['name'], PATHINFO_EXTENSION);
            // dd($fileExt);
            
            // Check if File inside zip is in CSV format
            if ($fileExt !== ".csv") {
                $not_supported = "Could not found CSV file in your Zip $originalFileName!";
                
                return $this->render('file_upload/index.html.twig', [
                    'controller_name' => 'FileUploadController',
                    'invalid_format' => $not_supported
                ]);                
                // dd("Could not found CSV file in your Zip!");
            }

            // Filename after renaming (string)
            $filename = $random_num . $fileExt; 
            
            // Upload
            $zipArchive->extractTo($uploads_directory, $file1);
            $zipArchive->close();
            rename($uploads_directory."/".$file1, $uploads_directory."/".$filename);
        } 
        // Move directly if it is CSV
        else if ($extension == 'csv') {
            // Filename after renaming (string)
            // $filename = $random_num . '.' . $file->guessExtension();
            $filename = $random_num . '.' . $extension;
            // dd($filename);
            $file->move(
                $uploads_directory,
                $filename
            );
        }
        // Set filename as session
        $session = new Session();
        //$session->start();
        $session->set('user_id', $random_num);
        // $file_full = Absolute file path
        $file_full = $uploads_directory . '/' . $filename;
        // Open and extract csv
        $filesize = filesize($file_full); // bytes
        $filesize = round($filesize / 1024, 2);
        if (($handle = fopen($file_full, "r")) !== false) {
            $columns = fgetcsv($handle, 3000, ",");
            fclose($handle);
        }
    //     //sql query for creating csv table
    //     $create_table_sql= 'CREATE TABLE '.$random_num.' (';
    //     for($i=0;$i<count($columns); $i++) {
    //         $create_table_sql .= '`' . $columns[$i].'` TEXT ';

    //         if($i < count($columns) - 1)
    //             $create_table_sql .= ',';
    //     }
    //     $create_table_sql .= ')';

    //     //sql query for importing data to table from csv
    //     $insert_sql=<<<eof
    //     LOAD DATA LOCAL INFILE '$file_full' 
    //     INTO TABLE $random_num 
    //     FIELDS TERMINATED BY ',' 
    //     ENCLOSED BY '"'
    //     LINES TERMINATED BY '\n'
    //     IGNORE 1 LINES;
    //     eof;
    //    $conn = $entityManager->getRepository(MetaTable::class)->createOrDropDynamicTable($create_table_sql);
    //    $conn = $entityManager->getRepository(MetaTable::class)->addDataToTable($insert_sql);
    
        // Save to meta_table in db

        $metaTable = new MetaTable();
        $em = $doctrine->getManager();
        $metaTable->setFilename($filename);
        $metaTable->setFilesize($filesize);
        $metaTable->setColumns($columns);
        $metaTable->setOriginalFileName($originalFileName);
        $em->persist($metaTable);
        $em->flush();

        return $this->redirectToRoute('app_modify_file', [
            'filename' => (string) $filename,
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
     * @Route("/export", name="app_export")
     */
    public function export(Request $request, EntityManagerInterface $entityManager): Response
    {      
        ob_start();
        $uploads_directory = $this->getParameter('uploads_directory');
        $filename = $request->get('filename');
        $file_full = $uploads_directory . '/' . $filename;

        // Modified Index wise Columns
        $original_column = $request->get('original_cols');
        $renamed_column = $request->get('text');
        // Array of columns from UI
        $list = array($renamed_column); 
        
        $ext = '.csv' ;
        // $table = str_replace($ext, '', $filename);

        // Process only if Table exixts (Page is neither refreshed nor multiple times exported)
        // if ($entityManager->getRepository(MetaTable::class)->table_exists($table)) {        
            
            $fp = fopen('php://output', 'w');
            foreach ($list as $fields) {
                fputcsv($fp, $fields);
            }
            
        //     //sql query for fetching modified csv data from table
        //     $fetch_sql = 'SELECT ';
        //     for($i=0;$i<count($original_column); $i++) {
        //         $fetch_sql .= '`' . $original_column[$i] . '`' ;
        //         if($i < count($original_column) - 1)
        //             $fetch_sql .= ',';
        //     }
        //     $fetch_sql .= ' FROM ' .$table;
     
        //     $conn = $entityManager->getRepository(MetaTable::class)->getUpdatedcsv($fetch_sql);
            $metaRecord = $entityManager->getRepository(MetaTable::class)->findOneBy(['filename' => $filename]);
            $originalFileName = $metaRecord->getOriginalFileName();
            $convertedFileName = "converted_" . $originalFileName;
        
        //     $reader = Reader::createFromPath($file_full);
        //     $reader->setHeaderOffset(0);
        //     foreach ($conn as $fields) {
        //         fputcsv($fp, $fields);
        //     }
        
            $session = $request->getSession();
            $session->invalidate();
            $response = new Response();
            $response->headers->set('Content-Type', 'binary/octet-stream');
            
            // It's gonna output in a converted_originalFilename.csv file
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$convertedFileName.'.csv"');
            
            // Delete file from uploads directory
            $fileSystem = new Filesystem();
            $fileSystem->remove($file_full);
            
        //     // Delete file_table from Database
        //     $drop_table_sql="DROP TABLE `$table`";
        //     $conn = $entityManager->getRepository(MetaTable::class)->createOrDropDynamicTable($drop_table_sql);

        //     // return new BinaryFileResponse();
            
        //     // $response->headers->set('Location', 'file_upload/index.html.twig');
        //     // header('Location : /file_upload');
            return $response;
            ob_clean();
        // }
        // else {
        //     return $this->render('/failure/table_failure.html.twig');
        // }
    }
       
}







