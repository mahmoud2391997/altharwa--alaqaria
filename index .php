<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 3600");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Main request handling
try {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        handlePdfUpload();
    } 
    elseif ($_SERVER['REQUEST_METHOD'] == 'GET') {
        serveFrontend();
    }
    else {
        throw new Exception('Invalid request method');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

function serveFrontend() {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($requestPath == '/' || $requestPath == '/index.html') {
        $indexFile = __DIR__ . '/index.html';
        if (file_exists($indexFile)) {
            header('Content-Type: text/html');
            readfile($indexFile);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found']);
    exit;
}

function handlePdfUpload() {
    // Validate file upload
    if (!isset($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }

    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $_FILES['file']['error']);
    }

    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);
    finfo_close($finfo);
    
    if ($mimeType != "application/pdf") {
        throw new Exception('File is not a PDF');
    }

    // Process the PDF file and get response
    $response = processPdfFile($_FILES['file']);
    
    // Return successful response
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Your original processPdfFile function remains exactly the same
function processPdfFile($uploadedFile) {
    // Validate file type
    if ($uploadedFile['type'] != "application/pdf") {
        throw new Exception('File is not a PDF');
    }

    // Check if PDF parser exists
    if (!file_exists("pdfparser/alt_autoload.php")) {
        throw new Exception('PDF parser library not found');
    }

    // Process the PDF
    require "pdfparser/alt_autoload.php";
    $parser = new \Smalot\PdfParser\Parser();
    
    try {
        $pdf = $parser->parseFile($uploadedFile['tmp_name']);
        $text = $pdf->getText();
        
        if (empty($text)) {
            throw new Exception('Could not extract text from PDF');
        }
    } catch (Exception $e) {
        throw new Exception('PDF parsing failed: ' . $e->getMessage());
    }

    function add_string_after($text, $character, $stringAdd, $afterCount) {
        $text = str_replace($character, "@@@@".$character, $text);
        $text_arr = explode("@@@@", trim($text));
        for($x = 0; $x < count($text_arr); $x++) {
            if(substr_count($text_arr[$x],$character)) { 
                $text_arr[$x] = substr_replace($text_arr[$x], $stringAdd, $afterCount, 0);
            }
        }
        return implode("",$text_arr);
    }

    function get_string_between($string, $start, $end) {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    function get_string_clean($string) {
        // First normalize all line endings
        $string = str_replace(["\r\n", "\r"], "\n", $string);
        
        // Remove special space characters and normalize whitespace
        $string = str_replace(" ‎", " ", $string);
        $string = str_replace("\t", " ", $string);
        
        // Replace multiple spaces with single space
        $string = preg_replace('/\s+/', ' ', $string);
        
        // Remove any remaining newlines
        $string = str_replace("\n", " ", $string);
        
        // Final trim
        return trim($string);
    }
    // Extract data from PDF text
    $lines = explode("Contract Data", $text);
    if (count($lines) < 2) {
        throw new Exception('PDF format not recognized - missing "Contract Data" section');
    }
    
    // Process Contract Data
    $Contract_Data_Title = [];
    $Contract_Data_Value = [];
    $linesData = explode("Lessor Data", $lines[1]);
    $result = $linesData[0];
    
    $Contract_No = get_string_clean(get_string_between($result, 'Contract No.', ':'));
    $Contract_Type = get_string_clean(get_string_between($result, 'Contract Type', ':'));
    $Contract_Date = get_string_clean(get_string_between($result, 'Date', ':'));
    $Start_Date = get_string_clean(get_string_between($result, 'Tenancy Start Date', ':'));
    $End_Date = get_string_clean(get_string_between($result, 'Tenancy End Date', ':'));
    
    $Contract_Data_Value['Contract_No'] = $Contract_No;
    $Contract_Data_Value['Contract_Type'] = $Contract_Type;
    $Contract_Data_Value['Contract_Date'] = $Contract_Date;
    $Contract_Data_Value['Start_Date'] = $Start_Date;
    $Contract_Data_Value['End_Date'] = $End_Date;
    
    $Contract_Data_Title['Contract_No'] = "رقم سجل العقد";
    $Contract_Data_Title['Contract_Type'] = "نوع العقد";
    $Contract_Data_Title['Contract_Date'] = "تاريخ ابرام العقد";
    $Contract_Data_Title['Start_Date'] = "تاريخ بداية مدة الايجار";
    $Contract_Data_Title['End_Date'] = "تاريخ نهاية مدة الايجار";

    // Process Tenant Data
    $lines = explode("Tenant Data", $lines[1]);
    $Tenant = [];
    $Representative = [];
    $table_Tenant = "";
    
    if(count($lines) > 1) {
        $table_Tenant = "true";
        $linesData = explode("Tenant Representative Data", $lines[1]);
        
        if (!empty(stripos($linesData[0], "Company"))) {
            $result = $linesData[0];
            
            $Company_words = explode("<br />", nl2br(get_string_between($result, 'name/Founder', ':')));
            for($x = 0; $x < count($Company_words); $x++) {
                $Company_words[$x] = trim(implode(" ",array_reverse(explode(" ", $Company_words[$x]))));
                if($Company_words[$x] == "اﺳﻢ") $Company_words[$x] = "";
            }
            
            $Tenant['company'] = get_string_clean(implode(" ",$Company_words));
            $Tenant['organization'] = get_string_clean(get_string_between($result, 'Organization Type', ':'));
            $Tenant['unified'] = get_string_clean(get_string_between($result, 'Unified Number', 'اﻟﻤﻮﺣﺪ'));
            $Tenant['cr_no'] = get_string_clean(get_string_between($result, 'CR No.', ':'));
            $Tenant['date'] = get_string_clean(get_string_between($result, 'CR Date', ':'));
            $Tenant['issued'] = get_string_clean(implode(" ",array_reverse(explode(" ", get_string_between($result, 'Issued by', ':')))));
        } else {
            $table_Tenant = "false";
        }
        
        // Process Representative Data
        if(count($Tenant) > 0 || $table_Tenant == "false") {
            if($table_Tenant != "false") {
                $linesData = explode("Brokerage Entity and Broker Data", $linesData[1]);
            }
            
            $result = $linesData[0];
            $Nationality_words = explode("<br />", nl2br(get_string_between($result, 'Nationality', ':')));
            
            for($x = 0; $x < count($Nationality_words); $x++) {
                $Nationality_words[$x] = implode(" ",array_reverse(explode(" ", $Nationality_words[$x])));
            }
            
            $Representative['name'] = get_string_clean(implode(" ",array_reverse(explode(" ", get_string_between($result, 'Name', ':')))));
            $Representative['nationality'] = get_string_clean(implode(" ",$Nationality_words));
            $Representative['type'] = get_string_clean(implode(" ",array_reverse(explode(" ", get_string_between($result, 'ID Type', ':')))));
            $Representative['id'] = get_string_clean(get_string_between($result, 'ID No.', ':'));
            $Representative['mobile'] = get_string_clean(get_string_between($result, 'Mobile No.', ':'));
            $Representative['email'] = str_replace(" ", "", get_string_clean(get_string_between($result, 'Email', ':')));
        }
        
        $lines = explode("Rent Payments Schedule", $linesData[1]);
    } else {
        $lines = explode("Rent Payments Schedule", $lines[0]);
    }
    
    // Prepare Tenant Data arrays
    $Tenant_Data_Title = [];
    $Tenant_Data_Value = [];
    $Tenant_Representative_Data_Title = [];
    $Tenant_Representative_Data_Value = [];
    
    if(count($Tenant) > 0) {
        $Tenant_Data_Title = [
            'company' => "أسم المؤسسة\الشركة",
            'organization' => "نوع المنظمة",
            'unified' => "الرقم الموحد",
            'cr_no' => "رقم السجل التجاري",
            'date' => "تاريخ السجل التجاري",
            'issued' => "جهة الاصدار"
        ];
        $Tenant_Data_Value = $Tenant;
    }
    
    if(count($Representative) > 0) {
        $Tenant_Representative_Data_Title = [
            'name' => "الاسم",
            'nationality' => "الجنسية",
            'type' => "نوع الهوية",
            'id' => "رقم الهوية",
            'mobile' => "رقم الجوال",
            'email' => "البريد الالكتروني"
        ];
        $Tenant_Representative_Data_Value = $Representative;
    }
    
    // Process Unit Data
    $linesData = explode("Units Data", $linesData[1]);
    $result = "Tenant Authority".$linesData[1];
    
    $Unit_Data_Value = [
        'Unit_Type' => get_string_clean(get_string_between($result, 'Unit Type', ':')),
        'Unit_No' => get_string_clean(get_string_between($result, 'Unit No.', ':'))
    ];
    
    $Unit_Data_Title = [
        'Unit_Type' => "نوع الوحدة",
        'Unit_No' => "رقم الوحدة"
    ];
    
    // Process Rent Payments Schedule
    $lines = explode("Obligations", $lines[1]);
    $result = "Rent Payments Schedule".$lines[0];
    
    $title_table = nl2br(get_string_between($result, 'Rent Payments Schedule', '.No').".No");
    $title_table_arr = explode("<br />", trim($title_table));
    
    $item_title_ar = [];
    $i = 0;
    
    foreach($title_table_arr as $item) {
        $item = trim(implode(" ", explode(" ", $item)));
        
        $mappings = [
            'Due Date(AH)' => "تاريخ الاستحقاق (ه)",
            'Issued Date(AH)' => "تاريخ الإصدار (ه)",
            'Due Date(AD)' => "تاريخ الاستحقاق (م)",
            'Issued Date(AD)' => "تاريخ الإصدار (م)",
            'Total value' => "إجمالى القيمة",
            'Services' => "قيمة المبالغ الثابتة",
            'VAT' => "ضريبة القيمة المضافة",
            'Rent value' => "قيمة الإيجار",
            'Amount' => "القيمة",
            'End of payment deadline(AH)' => "نهاية مهلة السداد (ه)",
            'End of payment deadline(AD)' => "نهاية مهلة السداد (م)",
            'Rental Period' => "الفترة الإيجارية",
            '.No' => "التسلسل"
        ];
        
        if(isset($mappings[$item])) {
            $item_title_ar[$i++] = $mappings[$item];
        }
    }
    
    // Process payment table
    $lines = explode("No", $lines[0]);
    $lines2 = nl2br($lines[1]);
    $lines = explode("<br />", $lines2);
    
    $table = [];
    $t = 0;
    
    foreach($lines as $line) {
        $line = add_string_after($line, ".", " ", 3);
        $line = add_string_after($line, "-", " ", 3);
        $line = str_replace(" -", "-", $line);
        $line = preg_replace('/\r?\n|\r/', ' ', $line);
        $line = str_replace("	", " ", $line);
        
        $lineParts = explode(" ", $line);
        $filteredParts = [];
        
        foreach($lineParts as $part) {
            if(!empty($part) && preg_match('~[0-9]+~', $part)) {
                $filteredParts[] = $part;
            }
        }
        
        if(count($filteredParts) > 5) {
            $table[$t++] = $filteredParts;
        }
    }
    
    // Prepare final response
    $response = [
        'Contract_Data_Title' => $Contract_Data_Title ?? [],
        'Contract_Data_Value' => $Contract_Data_Value ?? [],
        'Tenant_Data_Title' => $Tenant_Data_Title ?? [],
        'Tenant_Data_Value' => $Tenant_Data_Value ?? [],
        'Tenant_Representative_Data_Title' => $Tenant_Representative_Data_Title ?? [],
        'Tenant_Representative_Data_Value' => $Tenant_Representative_Data_Value ?? [],
        'Unit_Data_Title' => $Unit_Data_Title ?? [],
        'Unit_Data_Value' => $Unit_Data_Value ?? [],
        'Rent_Payments_Schedule_Title' => $item_title_ar ?? [],
        'Rent_Payments_Schedule_Value' => $table ?? []
    ];
    
    return $response;
}