<?php

/**
 * ContestController
 * 
 */
class ContestController extends Controller {

	private static $private_users_list;
	private static $hasPrivateUsers;
	private static $problems;
	private static $contest;

	/**
	 *
	 * List contests
	 */
	public static function apiList(Request $r = null) {
		$result = ContestsDAO::search(new Contests(array(
							"public" => 1
						)));

		$array_result = array();

		// @TODO make daos return associative arrays
		// if requestes in order to discard this loop
		foreach ($result as $r) {
			array_push($array_result, $r->asArray());
		}

		return array(
			"number_of_results" => sizeof($array_result),
			"results" => $array_result
		);
	}

	public static function validateDetails(Request $r) {

		Validators::isStringNonEmpty($r["contest_alias"], "contest_alias");

		// If the contest is private, verify that our user is invited
		try {
			self::$contest = ContestsDAO::getByAlias($r["contest_alias"]);
		} catch (Exception $e) {
			throw new InvalidDatabaseOperationException($e);
		}

		if (is_null(self::$contest)) {
			throw new NotFoundException("Contest not found");
		}


		if (self::$contest->getPublic() === '0') {
			try {
				if (is_null(ContestsUsersDAO::getByPK($r["current_user_id"], self::$contest->getContestId())) && !Authorization::IsContestAdmin($r["current__user_id"], self::$contest)) {
					throw new ForbiddenAccessException();
				}
			} catch (ApiException $e) {
				// Propagate exception
				throw $e;
			} catch (Exception $e) {
				// Operation failed in the data layer
				throw new InvalidDatabaseOperationException($e);
			}
		}

		// If the contest has not started, user should not see it, unless it is admin
		if (!self::$contest->hasStarted($r["current__user_id"]) && !Authorization::IsContestAdmin($r["current__user_id"], self::$contest)) {
			$exception = new PreconditionFailedException("Contest has not started yet.");
			$exception->addCustomMessageToArray("start_time", strtotime(self::$contest->getStartTime()));

			throw $exception;
		}
	}

	public static function apiDetails(Request $r) {

		// Crack the request to get the current user
		self::authenticateRequest($r);

		self::validateDetails($r);

		// Check cache first
		$cache = new Cache(Cache::CONTEST_INFO, $r["contest_alias"]);
		$result = $cache->get();

		if (is_null($result)) {
			// Create array of relevant columns
			$relevant_columns = array("title", "description", "start_time", "finish_time", "window_length", "alias", "scoreboard", "points_decay_factor", "partial_score", "submissions_gap", "feedback", "penalty", "time_start", "penalty_time_start", "penalty_calc_policy");

			// Initialize response to be the contest information
			$result = self::$contest->asFilteredArray($relevant_columns);

			$result['start_time'] = strtotime($result['start_time']);
			$result['finish_time'] = strtotime($result['finish_time']);

			// Get problems of the contest
			$key_problemsInContest = new ContestProblems(
							array("contest_id" => self::$contest->getContestId()));
			try {
				$problemsInContest = ContestProblemsDAO::search($key_problemsInContest, "order");
			} catch (Exception $e) {
				// Operation failed in the data layer
				throw new InvalidDatabaseOperationException($e);
			}

			// Add info of each problem to the contest
			$problemsResponseArray = array();

			// Set of columns that we want to show through this API. Doesn't include the SOURCE
			$relevant_columns = array("title", "alias", "validator", "time_limit", "memory_limit", "visits", "submissions", "accepted", "dificulty", "order");

			foreach ($problemsInContest as $problemkey) {
				try {
					// Get the data of the problem
					$temp_problem = ProblemsDAO::getByPK($problemkey->getProblemId());
				} catch (Exception $e) {
					// Operation failed in the data layer
					throw new InvalidDatabaseOperationException($e);
				}

				// Add the 'points' value that is stored in the ContestProblem relationship
				$temp_array = $temp_problem->asFilteredArray($relevant_columns);
				$temp_array["points"] = $problemkey->getPoints();

				// Save our array into the response
				array_push($problemsResponseArray, $temp_array);
			}

			// Save the time of the first access
			try {
				$contest_user = ContestsUsersDAO::CheckAndSaveFirstTimeAccess(
								$r["current_user_id"], self::$contest->getContestId());
			} catch (Exception $e) {
				// Operation failed in the data layer
				throw new InvalidDatabaseOperationException($e);
			}

			// Add problems to response
			$result['problems'] = $problemsResponseArray;

			$cache->set($result, APC_USER_CACHE_CONTEST_INFO_TIMEOUT);
		}// closes if( $result == null )
		// Adding timer info separately as it depends on the current user and we don't
		// want this to get generally cached for everybody
		// Save the time of the first access
		try {
			$contest_user = ContestsUsersDAO::CheckAndSaveFirstTimeAccess(
							$r["current_user_id"], self::$contest->getContestId());
		} catch (Exception $e) {
			// Operation failed in the data layer
			throw new InvalidDatabaseOperationException($e);
		}

		// Add time left to response
		if (self::$contest->getWindowLength() === null) {
			$result['submission_deadline'] = strtotime(self::$contest->getFinishTime());
		} else {
			$result['submission_deadline'] = min(
					strtotime(self::$contest->getFinishTime()), strtotime($contest_user->getAccessTime()) + self::$contest->getWindowLength() * 60);
		}

		$result["status"] = "ok";
		return $result;
	}

	/**
	 * Creates a new contest
	 * 
	 * @param Request $r
	 * @return array
	 * @throws DuplicatedEntryInDatabaseException
	 * @throws InvalidDatabaseOperationException
	 */
	public static function apiCreate(Request $r) {

		// Authenticate user
		self::authenticateRequest($r);

		// Validate request
		self::validateCreateRequest($r);

		// Create and populate a new Contests object
		$contest = new Contests();

		$contest->setPublic($r["public"]);
		$contest->setTitle($r["title"]);
		$contest->setDescription($r["description"]);
		$contest->setStartTime(gmdate('Y-m-d H:i:s', $r["start_time"]));
		$contest->setFinishTime(gmdate('Y-m-d H:i:s', $r["finish_time"]));
		$contest->setWindowLength($r["window_length"] == "NULL" ? NULL : $r["window_length"]);
		$contest->setDirectorId($r["current_user_id"]);
		$contest->setRerunId(0); // NYI
		$contest->setAlias($r["alias"]);
		$contest->setScoreboard($r["scoreboard"]);
		$contest->setPointsDecayFactor($r["points_decay_factor"]);
		$contest->setPartialScore($r["partial_score"]);
		$contest->setSubmissionsGap($r["submissions_gap"]);
		$contest->setFeedback($r["feedback"]);
		$contest->setPenalty(max(0, intval($r["penalty"])));
		$contest->setPenaltyTimeStart($r["penalty_time_start"]);
		$contest->setPenaltyCalcPolicy($r["penalty_calc_policy"]);

		if (!is_null($r["show_scoreboard_after"])) {
			$contest->setShowScoreboardAfter($r["show_scoreboard_after"]);
		} else {
			$contest->setShowScoreboardAfter("1");
		}


		// Push changes
		try {
			// Begin a new transaction
			ContestsDAO::transBegin();

			// Save the contest object with data sent by user to the database
			ContestsDAO::save($contest);

			// If the contest is private, add the list of allowed users
			if ($r["public"] == 0 && self::$hasPrivateUsers) {
				foreach (self::$private_users_list as $userkey) {
					// Create a temp DAO for the relationship
					$temp_user_contest = new ContestsUsers(array(
								"contest_id" => $contest->getContestId(),
								"user_id" => $userkey,
								"access_time" => "0000-00-00 00:00:00",
								"score" => 0,
								"time" => 0
							));

					// Save the relationship in the DB
					ContestsUsersDAO::save($temp_user_contest);
				}
			}

			if (!is_null($r['problems'])) {
				foreach (self::$problems as $problem) {
					$contest_problem = new ContestProblems(array(
								'contest_id' => $contest->getContestId(),
								'problem_id' => $problem['id'],
								'points' => $problem['points']
							));

					ContestProblemsDAO::save($contest_problem);
				}
			}

			// End transaction transaction
			ContestsDAO::transEnd();
		} catch (Exception $e) {
			// Operation failed in the data layer, rollback transaction 
			ContestsDAO::transRollback();

			// Alias may be duplicated, 1062 error indicates that
			if (strpos($e->getMessage(), "1062") !== FALSE) {
				throw new DuplicatedEntryInDatabaseException("alias already exists. Please choose a different alias.", $e);
			} else {
				throw new InvalidDatabaseOperationException($e);
			}
		}

		Logger::log("New Contest Created: " . $r['alias']);
		return array("status" => "ok");
	}

	/**
	 * Validates that Request contains expected data. In case of error, this 
	 * function throws.
	 * 
	 * @param Request $r
	 * @throws InvalidParameterException
	 */
	private static function validateCreateRequest(Request $r) {

		Validators::isStringNonEmpty($r["title"], "title");
		Validators::isStringNonEmpty($r["description"], "description");

		Validators::isNumber($r["start_time"], "start_time");
		Validators::isNumber($r["finish_time"], "finish_time");
		if ($r["start_time"] > $r["finish_time"]) {
			throw new InvalidParameterException("start_time cannot be after finish_time");
		}

		// Calculate contest length:
		$contest_length = $r["finish_time"] - $r["start_time"];

		// Window_length is optional
		Validators::isNumberInRange(
				$r["window_length"], "window_length", 0, floor($contest_length) / 60, false
		);

		Validators::isInEnum($r["public"], "public", array("0", "1"));
		Validators::isStringOfMaxLength($r["alias"], "alias", 32);
		Validators::isNumberInRange($r["scoreboard"], "scoreboard", 0, 100);
		Validators::isNumberInRange($r["points_decay_factor"], "points_decay_factor", 0, 1);
		Validators::isInEnum($r["partial_score"], "partial_score", array("0", "1"));
		Validators::isNumberInRange($r["submissions_gap"], "submissions_gap", 0, $contest_length);

		Validators::isInEnum($r["feedback"], "feedback", array("no", "yes", "partial"));
		Validators::isInEnum($r["penalty_time_start"], "penalty_time_start", array("contest", "problem", "none"));
		Validators::isInEnum($r["penalty_calc_policy"], "penalty_calc_policy", array("sum", "max"));

		Logger::log("-----");
		if ($r["public"] == 0 && !is_null($r["private_users"])) {
			Logger::log("/////");
			// Validate that the request is well-formed
			//  @todo move $this
			self::$private_users_list = json_decode($r["private_users"]);
			if (is_null(self::$private_users_list)) {
				throw new InvalidParameterException("private_users" . Validators::IS_INVALID);
			}

			// Validate that all users exists in the DB
			foreach (self::$private_users_list as $userkey) {
				if (is_null(UsersDAO::getByPK($userkey))) {
					throw new InvalidParameterException("private_users contains a user that doesn't exists");
				}
			}

			// Turn on flag to add private users later
			self::$hasPrivateUsers = true;
		}

		// Problems is optional
		if (!is_null($r['problems'])) {
			self::$problems = array();

			foreach (json_decode($r['problems']) as $problem) {
				$p = ProblemsDAO::getByAlias($problem->problem);
				array_push(self::$problems, array(
					'id' => $p->getProblemId(),
					'alias' => $problem->problem,
					'points' => $problem->points
				));
			}
		}

		Validators::isInEnum($r["show_scoreboard_after"], "show_scoreboard_after", array("0", "1"), false);
	}

	/**
	 * Adds a problem to a contest
	 * 
	 * @param Request $r
	 * @return array
	 * @throws InvalidDatabaseOperationException
	 */
	public static function apiAddProblem(Request $r) {

		// Authenticate user
		self::authenticateRequest($r);

		// Validate the request and get the problem and the contest in an array
		$params = self::validateAddToContestRequest($r);

		try {
			$relationship = new ContestProblems(array(
						"contest_id" => $params["contest"]->getContestId(),
						"problem_id" => $params["problem"]->getProblemId(),
						"points" => $r["points"],
						"order" => is_null($r["order_in_contest"]) ?
								1 : $r["order_in_contest"]));

			ContestProblemsDAO::save($relationship);
		} catch (Exception $e) {
			throw new InvalidDatabaseOperationException($e);
		}

		return array("status" => "ok");
	}

	/**
	 * Validates the request for AddToContest and returns an array with 
	 * the problem and contest DAOs
	 * 
	 * @throws InvalidDatabaseOperationException
	 * @throws InvalidParameterException
	 * @throws ForbiddenAccessException
	 */
	private static function validateAddToContestRequest(Request $r) {

		Validators::isStringNonEmpty($r["contest_alias"], "contest_alias");

		// Only director is allowed to create problems in contest
		try {
			$contest = ContestsDAO::getByAlias($r["contest_alias"]);
		} catch (Exception $e) {
			// Operation failed in the data layer
			throw new InvalidDatabaseOperationException($e);
		}

		if (is_null($contest)) {
			throw new InvalidParameterException("Contest not found");
		}

		// Only contest admin is allowed to create problems in contest
		if (!Authorization::IsContestAdmin($r["current_user_id"], $contest)) {
			throw new ForbiddenAccessException("Cannot add problem. You are not the contest director.");
		}

		Validators::isStringNonEmpty($r["problem_alias"], "problem_alias");

		try {
			$problem = ProblemsDAO::getByAlias($r["problem_alias"]);
		} catch (Exception $e) {
			// Operation failed in the data layer
			throw new InvalidDatabaseOperationException($e);
		}

		if (is_null($problem)) {
			throw new InvalidParameterException("Problem not found");
		}

		Validators::isNumberInRange($r["points"], "points", 0, INF);
		Validators::isNumberInRange($r["order_in_contest"], "order_in_contest", 0, INF, false);

		return array(
			"contest" => $contest,
			"problem" => $problem);
	}

	/**
	 * Adds a user to a contest.
	 * By default, any user can view details of public contests.
	 * Only users added through this API can view private contests
	 * 
	 * @param Request $r
	 * @return array
	 * @throws InvalidDatabaseOperationException
	 * @throws ForbiddenAccessException
	 */
	public static function apiAddUser(Request $r) {
		
		// Authenticate logged user
		self::authenticateRequest($r);
		
		
		$user_to_add  = null;
		
		// Check contest_alias        
		Validators::isStringNonEmpty($r["contest_alias"], "contest_alias");
		Validators::isNumber($r["user_id"], "user_id");                                

        try
        {
            self::$contest = ContestsDAO::getByAlias($r["contest_alias"]);
			$user_to_add = UsersDAO::getByPK($r["user_id"]);
        }
        catch(Exception $e)
        {  
            // Operation failed in the data layer
           throw new InvalidDatabaseOperationException($e);
        }                
        
		// Only director is allowed to create problems in contest
        if(!Authorization::IsContestAdmin($r["current_user_id"], self::$contest))
        {
            throw new ForbiddenAccessException();
        }		
		
		$contest_user = new ContestsUsers();
        $contest_user->setContestId(self::$contest->getContestId());
        $contest_user->setUserId($r["user_id"]);
        $contest_user->setAccessTime("0000-00-00 00:00:00");
        $contest_user->setScore("0");
        $contest_user->setTime("0");
        
        // Save the contest to the DB
        try
        {
            ContestsUsersDAO::save($contest_user);
        }
        catch(Exception $e)
        {
           // Operation failed in the data layer
           throw new InvalidDatabaseOperationException($e);
        }
		
		return array("status" => "ok");
		
	}
}
