<?php

require_once 'libs/FileHandler.php';
require_once 'libs/ZipHandler.php';
require_once 'libs/Markdown/markdown.php';

/**
 * ProblemsController
 */
class ProblemsController extends Controller {

	private static $contest;
	private static $author;
	private static $problem;

	const MAX_ZIP_FILESIZE = 104857600; //100 * 1024 * 1024;

	private static $hasValidator = false;
	private static $filesToUnzip;
	private static $casesFiles;
		

	/**
	 * 
	 * @param type $pageSize
	 * @param type $pageNumber
	 * @param type $servidor
	 * @param type $orderBy
	 * @return type
	 */
	public static function getProblemList($pageSize = 10, $pageNumber = 1, $servidor = null, $orderBy = null) {

		//$condition = "server = '$servidor' and public = 1";
		//$results = ProblemsDAO::byPage ( $sizePage , $noPage , $condition , $servidor, $orderBy );		

		return ProblemsDAO::getAll($pageNumber, $pageSize, $orderBy);
	}

	/**
	 * 
	 * @return type
	 */
	public static function getJudgesList() {
		return array('uva' => "Universidad Valladolid |",
			'livearchive' => "Live Archive |",
			'pku' => "Pekin University |",
			'tju' => "<a href='?serv=tju'> Tianjing </a> |",
			'spoj' => "SPOJ");
	}

	/**
	 * Adds a problem from a remote server to the list of known
	 * problems.
	 *
	 * @return bool|string True if problem was added successfully.
	 *                     Error message, otherwise.
	 * @todo Add JSON responses
	 */
	public static function addRemoteProblem(
	$judge
	, $remote_id
	, $public = true
	) {

		try {
			$prob = new Problems();
			// Validating that $judge is in fact a valid judge happens
			// in setServidor
			$prob->setServidor($judge);
			$prob->setIdRemoto($remote_id);
			$prob->setPublico($public);

			ProblemsDAO::save($prob);
		} catch (Exception $e) {
			return $e->getMessage();
		}

		// If we make it this far, the problem was added successfully
		return true;
	}

	/**
	 * Adds one or more tags to a problem.
	 * This function should allow tagging multiple problems with multiple tags
	 * in a single function call. Arguments may be single values or arrays.
	 *
	 * @param mixed $problem_id The id may be a numeric problem_id or
	 *              a problem alias.
	 * @param int|array $tag_id Id of the tag (or tags) to be added.
	 *
	 * @return bool True if successful.
	 */
	public static function addTags(
	$problem_id
	, $tag_id
	) {
		
	}

	private static function validateCreateRequest($r) {

		Validators::isStringNonEmpty($r["author_username"], "author_username");

		// Check if author_username actually exists
		$u = new Users();
		$u->setUsername($r["author_username"]);
		$users = UsersDAO::search($u);
		if (count($users) !== 1) {
			throw new NotFoundException("author_username not found");
		}

		self::$author = $users[0];

		Validators::isStringNonEmpty($r["title"], "title");
		Validators::isStringNonEmpty($r["source"], "source");
		Validators::isStringNonEmpty($r["alias"], "alias");
		Validators::isInEnum($r["public"], "public", array("0", "1"));
		Validators::isInEnum($r["validator"], "validator", array("remote", "literal", "token", "token-caseless", "token-numeric"));
		Validators::isNumberInRange($r["time_limit"], "time_limit", 0, INF);
		Validators::isNumberInRange($r["memory_limit"], "memory_limit", 0, INF);
		Validators::isInEnum($r["order"], "order", array("normal", "inverse"));

		self::validateZip();
	}

	/**
	 * Validates problem zip given that a problem zip containts a testplan file 
	 * 
	 * @param ZipArchive $zip
	 * @param array $zipFilesArray
	 * @return boolean
	 * @throws InvalidParameterException
	 */
	private static function checkCasesWithTestplan(ZipArchive $zip, array $zipFilesArray) {

		// Get testplan contents into an array
		$testplan = $zip->getFromName("testplan");
		$testplan_array = array();

		// LOL RegEx magic to get test case names from testplan
		preg_match_all('/^\\s*([^#]+?)\\s+(\\d+)\\s*$/m', $testplan, $testplan_array);

		for ($i = 0; $i < count($testplan_array[1]); $i++) {
			// Check .in file
			$path = 'cases' . DIRECTORY_SEPARATOR . $testplan_array[1][$i] . '.in';
			if (!$zip->getFromName($path)) {
				throw new InvalidParameterException("Not able to find " . $testplan_array[1][$i] . " in testplan.");
			}

			self::$filesToUnzip[] = $path;
			self::$casesFiles[] = $path;

			// Check .out file
			$path = 'cases' . DIRECTORY_SEPARATOR . $testplan_array[1][$i] . '.out';
			if (!$zip->getFromName($path)) {
				throw new InvalidParameterException("Not able to find " . $testplan_array[1][$i] . " in testplan.");
			}

			self::$filesToUnzip[] = $path;
			self::$casesFiles[] = $path;
		}

		return true;
	}

	/**
	 * Helper function to check whether a string ends with $needle
	 * 
	 * @param string $haystack
	 * @param string $needle
	 * @param boolean $case
	 * @return boolean
	 */
	private static function endsWith($haystack, $needle, $case) {
		$expectedPosition = strlen($haystack) - strlen($needle);

		$ans = false;

		if ($case) {
			return strrpos($haystack, $needle, 0) === $expectedPosition;
		} else {
			return strripos($haystack, $needle, 0) === $expectedPosition;
		}
	}

	/**
	 * Validates the cases of a problem zip without testplan
	 * 
	 * @param ZipArchive $zip
	 * @param array $zipFilesArray
	 * @return boolean
	 * @throws InvalidParameterException
	 */
	private function checkCases(ZipArchive $zip, array $zipFilesArray) {
		// Necesitamos tener al menos 1 input
		$inputs = 0;
		$outputs = 0;

		// Add all files in cases/ that end either in .in or .out        
		for ($i = 0; $i < count($zipFilesArray); $i++) {
			$path = $zipFilesArray[$i];

			if (strpos($path, "cases/") == 0) {
				$isInput = self::endsWith($path, ".in", true);
				$isOutput = self::endsWith($path, ".out", true);

				if ($isInput || $isOutput) {
					self::$filesToUnzip[] = $path;
					self::$casesFiles[] = $path;
				}

				if ($isInput) {
					$inputs++;
				} else if ($isOutput) {
					$outputs++;
				}
			}
		}

		if ($inputs < 1) {
			throw new InvalidParameterException("0 inputs found. At least 1 input is needed.");
		}

		Logger::log($inputs . " found, " . $outputs . "found ");

		if (self::$hasValidator === false && $inputs != $outputs) {
			throw new InvalidParameterException("Inputs/Outputs mistmatch: " . $inputs . " found, " . $outputs . "found ");
		}

		return true;
	}

	/**
	 * 
	 * @param array $zipFilesArray
	 * @param ZipArchive $zip
	 * @return boolean
	 */
	private function checkProblemStatements(array $zipFilesArray, ZipArchive $zip) {
		Logger::log("Checking problem statements...");

		// We need at least one statement
		$statements = preg_grep('/^statements\/[a-zA-Z]{2}\.markdown$/', $zipFilesArray);

		if (count($statements) < 1) {
			throw new InvalidParameterException("No statements found");
		}

		// Add statements to the files to be unzipped
		foreach ($statements as $file) {
			// Revisar que los statements no esten vacíos                    
			if (strlen($zip->getFromName($file, 1)) < 1) {
				throw new InvalidParameterException("Statement {$file} is empty.");
			}

			Logger::log("Adding statements to the files to be unzipped: " . $file);
			self::$filesToUnzip[] = $file;
		}

		return true;
	}

	/**
	 * Entry point for zip validation
	 * 
	 * @return boolean
	 * @throws InvalidParameterException
	 */
	private static function validateZip() {

		Logger::log("Validating zip...");
		if (isset($_FILES['problem_contents']) &&
				!FileHandler::GetFileUploader()->IsUploadedFile($_FILES['problem_contents']['tmp_name'])) {
			throw new InvalidParameterException("problem_contents is invalid.");
		}

		self::$filesToUnzip = array();
		self::$casesFiles = array();
		$value = $_FILES['problem_contents']['tmp_name'];


		Logger::log("Opening $value...");
		$zip = new ZipArchive();
		$resource = $zip->open($value);

		$size = 0;
		if ($resource === TRUE) {
			// Get list of files
			for ($i = 0; $i < $zip->numFiles; $i++) {
				Logger::log("Found inside zip: '" . $zip->getNameIndex($i) . "'");
				$zipFilesArray[] = $zip->getNameIndex($i);

				// Sum up the size
				$statI = $zip->statIndex($i);
				$size += $statI['size'];

				// If the file is THE validator for custom outputs...
				if (stripos($zip->getNameIndex($i), 'validator.') === 0) {
					self::$hasValidator = true;
					self::$filesToUnzip[] = $zip->getNameIndex($i);
					Logger::log("Validator found: " . $zip->getNameIndex($i));
				}
			}

			if ($size > self::MAX_ZIP_FILESIZE) {
				throw new InvalidParameterException("Extracted zip size ($size) over {$maximumSize}MB. Rejecting.");
			}

			try {

				// Look for testplan
				if (in_array("testplan", $zipFilesArray)) {

					self::checkCasesWithTestplan($zip, $zipFilesArray);
					Logger::log("testplan found, checkCasesWithTestPlan=" . $returnValue);
					self::$filesToUnzip[] = 'testplan';
				} else {
					Logger::log("testplan not found");
					self::checkCases($zip, $zipFilesArray);
				}

				// Log files to unzip
				Logger::log("Files to unzip: ");
				foreach (self::$filesToUnzip as $file) {
					Logger::log($file);
				}

				// Look for statements
				$returnValue = self::checkProblemStatements($zipFilesArray, $zip);
				Logger::log("checkProblemStatements=" . $returnValue . ".");
			} catch (ApiException $e) {
				// Close zip
				Logger::log("Validation Failed. Closing zip");
				$zip->close();

				throw $e;
			}

			// Close zip
			Logger::log("closing zip");
			$zip->close();

			return $returnValue;
		} else {
			throw new InvalidParameterException("Unable to open zip." . ZipHandler::zipFileErrMsg($resource));
		}

		return true;
	}

	/**
	 * Create a new problem
	 * 
	 * @throws ApiException
	 * @throws DuplicatedEntryInDatabaseException
	 * @throws InvalidDatabaseOperationException
	 */
	public static function apiCreate(Request $r) {

		self::authenticateRequest($r);
		
		// Validates request
		self::validateCreateRequest($r);

		// Populate a new Problem object
		$problem = new Problems();
		$problem->setPublic($r["public"]);
		$problem->setTitle($r["title"]);
		$problem->setAlias($r["alias"]);
		$problem->setValidator($r["validator"]);
		$problem->setTimeLimit($r["time_limit"]);
		$problem->setMemoryLimit($r["memory_limit"]);
		$problem->setVisits(0);
		$problem->setSubmissions(0);
		$problem->setAccepted(0);
		$problem->setDifficulty(0);
		$problem->setSource($r["source"]);
		$problem->setOrder($r["order"]);
		$problem->setAuthorId(self::$author->getUserId());

		// Insert new problem
		try {

			// Save the contest object with data sent by user to the database
			ProblemsDAO::save($problem);

			// Create file after we know that alias is unique
			self::deployProblemZip(self::$filesToUnzip, self::$casesFiles, $r);
			
		} catch (ApiException $e) {

			// Operation failed in the data layer, rollback transaction 
			ProblemsDAO::transRollback();

			// Rollback the problem if deployed partially
			self::deleteProblemFromFilesystem(self::getDirpath($r));

			throw $e;
		} catch (Exception $e) {

			// Operation failed in the data layer, rollback transaction 
			ProblemsDAO::transRollback();

			// Rollback the problem if deployed partially
			self::deleteProblemFromFilesystem(self::getDirpath($r));

			// Alias may be duplicated, 1062 error indicates that
			if (strpos($e->getMessage(), "1062") !== FALSE) {
				throw new DuplicatedEntryInDatabaseException("contest_alias already exists.", $e);
			} else {
				throw new InvalidDatabaseOperationException($e);
			}
		}

		// Adding unzipped files to response
		$result["uploaded_files"] = self::$filesToUnzip;
		$result["status"] = "ok";

		// Invalidar cache
		$contestCache = new Cache(Cache::CONTEST_INFO, $r["contest_alias"]);
		$contestCache->delete();

		return $result;
	}

	/**
	 * 
	 * @param string $dirpath
	 * @param array $filesToUnzip
	 */
	private static function handleStatements($dirpath, array $filesToUnzip = null) {

		// Get a list of all available statements.
		// At this point, zip is validated and it has at least 1 statement. No need to check
		$statements = preg_grep('/^statements\/[a-zA-Z]{2}\.markdown$/', $filesToUnzip);
		Logger::log("Handling statements...");

		// Transform statements from markdown to HTML  
		foreach ($statements as $statement) {

			// Get the path to the markdown unzipped file
			$markdown_filepath = $dirpath . DIRECTORY_SEPARATOR . $statement;
			Logger::log("Reading file " . $markdown_filepath);

			// Read the contents of the original markdown file
			$markdown_file_contents = FileHandler::ReadFile($markdown_filepath);

			// Fix for Windows Latin-1 statements:
			// For now, assume that if it is not UTF-8, then it is Windows Latin-1 and then convert
			if (!mb_check_encoding($markdown_file_contents, "UTF-8")) {
				Logger::log("File is not UTF-8.");

				// Convert from ISO-8859-1 (Windows Latin1) to UTF-8
				Logger::log("Converting encoding from ISO-8859-1 to UTF-8 (Windows Latin1 to UTF-8, fixing accents)");
				$markdown_file_contents = mb_convert_encoding($markdown_file_contents, "UTF-8", "ISO-8859-1");

				// Then overwrite it into the statement file
				Logger::log("Overwriting file after encoding conversion: " . $markdown_filepath);
				FileHandler::CreateFile($markdown_filepath, $markdown_file_contents);
			} else {
				Logger::log("File is UTF-8. Nice :)");
			}

			// Transform markdown to HTML
			Logger::log("Transforming markdown to html");
			$html_file_contents = markdown($markdown_file_contents);

			// Get the language of this statement            
			$lang = basename($statement, ".markdown");

			$html_filepath = $dirpath . DIRECTORY_SEPARATOR . "statements" . DIRECTORY_SEPARATOR . $lang . ".html";

			// Save the HTML file in the path .../problem_alias/statements/lang.html            
			Logger::log("Saving HTML statement in " . $html_filepath);
			FileHandler::CreateFile($html_filepath, $html_file_contents);
		}
	}

	/**
	 * Handle unzipped cases
	 * 
	 * @param string $dirpath
	 * @param array $casesFiles
	 * @throws InvalidFilesystemOperationException
	 */
	private static function handleCases($dirpath, array $casesFiles) {

		Logger::log("Handling cases...");

		// Aplying dos2unix to cases
		$return_var = 0;
		$output = array();
		$dos2unix_cmd = "dos2unix " . $dirpath . DIRECTORY_SEPARATOR . "cases/* 2>&1";
		Logger::log("Applying dos2unix: " . $dos2unix_cmd);
		exec($dos2unix_cmd, $output, $return_var);

		// Log errors
		if ($return_var !== 0) {
			Logger::warn("dos2unix failed with error: " . $return_var);
		} else {
			Logger::log("dos2unix succeeded");
		}
		Logger::log(implode("\n", $output));


		// After dos2unixfication, we need to generate a zip file that will be
		// passed between grader and runners with the INPUT files...                
		// Create path to cases.zip and proper cmds
		$cases_zip_path = $dirpath . DIRECTORY_SEPARATOR . 'cases.zip';
		$cases_to_be_zipped = $dirpath . DIRECTORY_SEPARATOR . "cases/*.in";

		// cmd to be executed in console
		$zip_cmd = "zip -j " . $cases_zip_path . " " . $cases_to_be_zipped . " 2>&1";

		// Execute zip command
		$output = array();
		Logger::log("Zipping input cases using: " . $zip_cmd);
		exec($zip_cmd, $output, $return_var);

		// Check zip cmd return value
		if ($return_var !== 0) {
			// D:
			Logger::error("zipping cases failed with error: " . $return_var);
			throw new InvalidFilesystemOperationException("Error creating cases.zip. Please check log for details");
		} else {
			// :D
			Logger::log("zipping cases succeeded:");
			Logger::log(implode("\n", $output));
		}

		// Generate sha1sum for cases.zip distribution from grader to runners
		Logger::log("Writing to : " . $dirpath . DIRECTORY_SEPARATOR . "inputname");
		file_put_contents($dirpath . DIRECTORY_SEPARATOR . "inputname", sha1_file($cases_zip_path));
	}

	/**
	 * 
	 * @param string $dirpath
	 * @param string $path_to_contents_zip
	 * @return type
	 */
	private static function updateContentsDotZip($dirpath, $path_to_contents_zip) {

		// Delete whathever the user sent us
		if (!unlink($path_to_contents_zip)) {
			Logger::warn("Unable to delete contents.zip to replace with original contents!: " . $path_to_contents_zip);
			return;
		}

		// Set directory to the one where contents.zip is to handle paths inside
		// the zip correcly 
		$original_dir = getcwd();
		chdir($dirpath);

		// cmd to be executed in console
		// cases/*
		$output = array();

		$zip_cmd = "zip -r " . $path_to_contents_zip . " cases/* 2>&1";
		Logger::log("Zipping contents.zip cases using: " . $zip_cmd);
		exec($zip_cmd, $output, $return_var);

		// Check zip cmd return value
		if ($return_var !== 0) {
			// D:
			Logger::error("zipping cases/* contents.zip failed with error: " . $return_var);
		} else {
			// :D
			Logger::log("zipping cases contents.zip succeeded:");
			Logger::log(implode("\n", $output));
		}

		// 
		// statements/*
		$output = array();

		$zip_cmd = "zip -r " . $path_to_contents_zip . " statements/* 2>&1";
		Logger::log("Zipping contents.zip statements using: " . $zip_cmd);
		exec($zip_cmd, $output, $return_var);


		// Check zip cmd return value
		if ($return_var !== 0) {
			// D:
			Logger::error("zipping statements/* contents.zip failed with error: " . $return_var);
		} else {
			// :D
			Logger::log("zipping statements contents.zip succeeded:");
			Logger::log(implode("\n", $output));
		}

		// get back to original dir
		chdir($original_dir);
	}

	/**
	 * Returns the path where the problem contents will be placed
	 * 
	 * @param Request $r
	 * @return string
	 */
	private static function getDirpath(Request $r) {
		return PROBLEMS_PATH . DIRECTORY_SEPARATOR . $r["alias"];
	}

	/**
	 * 
	 * @param string $dirpath
	 * @return string
	 */
	private static function getFilepath($dirpath) {
		return $dirpath . DIRECTORY_SEPARATOR . 'contents.zip';
	}

	private static function deleteProblemFromFilesystem($dirpath) {
		// Drop contents into path required
		FileHandler::DeleteDirRecursive($dirpath);
	}

	/**
	 * 
	 * @param array $filesToUnzip
	 * @param array $casesFiles
	 * @param boolean $isUpdate
	 * @throws ApiException
	 */
	private static function deployProblemZip($filesToUnzip, $casesFiles, Request $r, $isUpdate = false) {

		try {
			// Create paths
			$dirpath = self::getDirpath($r);
			$filepath = self::getFilepath($dirpath);

			if ($isUpdate === true) {
				self::deleteProblemFromFilesystem($dirpath);
			}

			// Making target directory
			FileHandler::MakeDir($dirpath);

			// Move stuff uploaded by user from PHP realm to our directory
			FileHandler::MoveFileFromRequestTo('problem_contents', $filepath);

			// Unzip the user's zip
			ZipHandler::DeflateZip($filepath, $dirpath, $filesToUnzip);

			// Handle statements
			self::handleStatements($dirpath, $filesToUnzip);

			// Handle cases
			self::handleCases($dirpath, $casesFiles);

			// Update contents.zip
			self::updateContentsDotZip($dirpath, $filepath);
		} catch (Exception $e) {			
			throw new InvalidFilesystemOperationException("Unable to process problem_contents given. Please check the format. ", $e);
		}
	}

	/**
	 * Validates the request for AddToContest
	 * 
	 * @throws InvalidDatabaseOperationException
	 * @throws NotFoundException
	 * @throws ForbiddenAccessException
	 */
	private static function validateAddToContestRequest() {

		Validators::isStringNonEmpty($r["contest_alias"], "contest_alias");

		// Only director is allowed to create problems in contest
		try {
			self::$contest = ContestsDAO::getByAlias($r["contest_alias"]);
		} catch (Exception $e) {
			// Operation failed in the data layer
			throw new InvalidDatabaseOperationException($e);
		}

		if (is_null(self::$contest)) {
			throw new NotFoundException("Contest not found");
		}

		if (!Authorization::IsContestAdmin(self::$current_user_id, self::$contest)) {
			throw new ForbiddenAccessException();
		}


		Validators::isStringNonEmpty($r["problem_alias"], "problem_alias");

		// Only director is allowed to create problems in contest
		try {
			self::$problem = ProblemsDAO::getByAlias($r["problem_alias"]);
		} catch (Exception $e) {
			// Operation failed in the data layer
			throw new InvalidDatabaseOperationException($e);
		}

		if (is_null(self::$problem)) {
			throw new NotFoundException("Problem not found");
		}

		Validators::isNumberInRange($r["points"], "points", 0, INF);
		Validators::isNumberInRange($r["order_in_contest"], "order_in_contest", 0, INF, false);
	}

	/**
	 * Entry point for add problem to contest API
	 * 
	 * @throws InvalidDatabaseOperationException
	 */
	public function addToContest() {

		self::validateAddToContestRequest();

		try {
			$relationship = new ContestProblems(array(
						"contest_id" => self::$contest->getContestId(),
						"problem_id" => self::$problem->getProblemId(),
						"points" => $r["points"],
						"order" => is_null($r["order_in_contest"]) ?
								1 : $r["order_in_contest"]));

			ContestProblemsDAO::save($relationship);
		} catch (Exception $e) {
			throw new InvalidDatabaseOperationException($e);
		}

		return array("status" => "ok");
	}

}
