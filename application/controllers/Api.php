<?php if (! defined('BASEPATH')) exit('No direct script access allowed');

class API extends CI_Controller
{

	// Do absolutely nothing
	function index()
	{
		echo "nothing to see";
	}

	function help()
	{
		$this->load->model('user_model');

		// Check if users logged in

		if ($this->user_model->validate_session() == 0) {
			// user is not logged in
			redirect('user/login');
		}

		$this->load->model('api_model');

		$data['api_keys'] = $this->api_model->keys();

		$data['page_title'] = "API";

		$this->load->view('interface_assets/header', $data);
		$this->load->view('api/help');
		$this->load->view('interface_assets/footer');
	}


	function edit($key)
	{
		$this->load->model('user_model');

		// Check if users logged in

		if ($this->user_model->validate_session() == 0) {
			// user is not logged in
			redirect('user/login');
		}

		$this->load->model('api_model');

		$this->load->helper(array('form', 'url'));

		$this->load->library('form_validation');

		$this->form_validation->set_rules('api_desc', 'API Description', 'required');
		$this->form_validation->set_rules('api_key', 'API Key is required do not change this field', 'required');

		$data['api_info'] = $this->api_model->key_description($key);

		if ($this->form_validation->run() == FALSE) {
			$data['page_title'] = "Edit API Description";

			$this->load->view('interface_assets/header', $data);
			$this->load->view('api/description');
			$this->load->view('interface_assets/footer');
		} else {
			// Success!

			$this->api_model->update_key_description($this->input->post('api_key'), $this->input->post('api_desc'));

			$this->session->set_flashdata('notice', 'API Key <b>' . $this->input->post('api_key') . "</b> description has been updated.");

			redirect('api/help');
		}
	}

	function generate($rights)
	{
		$this->load->model('user_model');

		// Check if users logged in

		if ($this->user_model->validate_session() == 0) {
			// user is not logged in
			redirect('user/login');
		}


		$this->load->model('api_model');

		$data['api_keys'] = $this->api_model->generate_key($rights);

		redirect('api/help');
	}

	function delete($key)
	{
		$this->load->model('user_model');

		// Check if users logged in

		if ($this->user_model->validate_session() == 0) {
			// user is not logged in
			redirect('user/login');
		}


		$this->load->model('api_model');

		$this->api_model->delete_key($key);

		$this->session->set_flashdata('notice', 'API Key <b>' . $key . "</b> has been deleted");

		redirect('api/help');
	}

	// Example of authing
	function auth($key)
	{
		$this->load->model('api_model');
		header("Content-type: text/xml");
		if ($this->api_model->access($key) == "No Key Found" || $this->api_model->access($key) == "Key Disabled") {
			echo "<auth>";
			echo "<message>Key Invalid - either not found or disabled</message>";
			echo "</auth>";
		} else {
			echo "<auth>";
			echo "<status>Valid</status>";
			echo "<rights>" . $this->api_model->access($key) . "</rights>";
			echo "</auth>";
		}
	}

	function check_auth($key)
	{
		$this->load->model('api_model');
		header("Content-type: text/xml");
		if ($this->api_model->access($key) == "No Key Found" || $this->api_model->access($key) == "Key Disabled") {
			// set the content type as json
			header("Content-type: application/json");

			// set the http response code to 401
			http_response_code(401);

			// return the json with the status as failed
			echo json_encode(['status' => 'failed', 'reason' => "missing or invalid api key"]);
		} else {
			// set the content type as json
			header("Content-type: application/json");

			// set the http response code to 200
			http_response_code(200);
			// return the json
			echo json_encode(['status' => 'valid', 'rights' => $this->api_model->access($key)]);
		}
	}

	function station_info($key)
	{
		$this->load->model('api_model');
		$this->load->model('stations');
		header("Content-type: application/json");
		if (substr($this->api_model->access($key), 0, 1) == 'r') { /* Checkpermission for  _r_eading */
			$this->api_model->update_last_used($key);
			$userid = $this->api_model->key_userid($key);
			$station_ids = array();
			$stations = $this->stations->all_of_user($userid);
			foreach ($stations->result() as $row) {
				$result['station_id'] = $row->station_id;
				$result['station_profile_name'] = $row->station_profile_name;
				$result['station_gridsquare'] = $row->station_gridsquare;
				$result['station_callsign'] = $row->station_callsign;;
				$result['station_active'] = $row->station_active;
				array_push($station_ids, $result);
			}
			echo json_encode($station_ids);
		} else {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing or invalid api key"]);
		}
	}


	/*
	*
	*	Function: QSO
	*	Task: allows passing of ADIF data to Cloudlog
	*/
	function qso()
	{
		header('Content-type: application/json');

		$this->load->model('api_model');

		$this->load->model('stations');

		$return_msg = array();
		$return_count = 0;

		// Decode JSON and store
		$obj = json_decode(file_get_contents("php://input"), true);
		if ($obj === NULL) {
			// Decoding not valid try simple www-x-form-urlencoded
			$objTmp = file_get_contents("php://input");
			parse_str($objTmp, $obj);
			if ($obj === NULL) {
				echo json_encode(['status' => 'failed', 'reason' => "wrong JSON"]);
				die();
			}
		}

		if (!isset($obj['key']) || $this->api_model->authorize($obj['key']) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing api key"]);
			die();
		}

		$userid = $this->api_model->key_userid($obj['key']);

		if (!isset($obj['station_profile_id']) || $this->stations->check_station_against_user($obj['station_profile_id'], $userid) == false) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "station id does not belong to the API key owner."]);
			die();
		}

		if ($obj['type'] == "adif" && $obj['string'] != "") {
			// Load the logbook model for adding QSO records
			$this->load->model('logbook_model');

			// Load ADIF Parser
			$this->load->library('adif_parser');

			// Feed in the ADIF string
			$this->adif_parser->feed($obj['string']);

			// Create QSO Record
			while ($record = $this->adif_parser->get_record()) {
				if (count($record) == 0) {
					break;
				};


				if (isset($obj['station_profile_id'])) {
					if (isset($record['station_callsign']) && $this->stations->check_station_against_callsign($obj['station_profile_id'], $record['station_callsign']) == false) {
						http_response_code(401);
						echo json_encode(['status' => 'failed', 'reason' => "station callsign does not match station callsign in station profile."]);
						die();
					}

					if (!(isset($record['call'])) || (trim($record['call']) == '')) {
						http_response_code(401);
						echo json_encode(['status' => 'failed', 'reason' => "QSO Call is empty."]);
						die();
					}

					$this->api_model->update_last_used($obj['key']);

					$msg = $this->logbook_model->import($record, $obj['station_profile_id'], NULL, NULL, NULL, NULL, NULL, NULL, false, false, true);

					if ($msg == "") {
						$return_count++;
					} else {
						$return_msg[] = $msg;
					}
				}
			};
			http_response_code(201);
			echo json_encode(['status' => 'created', 'type' => $obj['type'], 'string' => $obj['string'], 'imported_count' => $return_count, 'messages' => $return_msg]);
		}
	}

	// API function to check if a callsign is in the logbook already
	function logbook_check_callsign()
	{
		header('Content-type: application/json');

		$this->load->model('api_model');

		// Decode JSON and store
		$obj = json_decode(file_get_contents("php://input"), true);
		if ($obj === NULL) {
			echo json_encode(['status' => 'failed', 'reason' => "wrong JSON"]);
			return;
		}

		if (!isset($obj['key']) || $this->api_model->authorize($obj['key']) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing api key"]);
			return;
		}

		if (!isset($obj['logbook_public_slug']) || !isset($obj['callsign'])) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing fields"]);
			return;
		}

		if ($obj['logbook_public_slug'] != "" && $obj['callsign'] != "") {

			$logbook_slug = $obj['logbook_public_slug'];
			$callsign = $obj['callsign'];

			// If $obj['band'] exists
			if (isset($obj['band'])) {
				$band = $obj['band'];
			} else {
				$band = null;
			}

			$this->load->model('logbooks_model');

			if ($this->logbooks_model->public_slug_exists($logbook_slug)) {
				$logbook_id = $this->logbooks_model->public_slug_exists_logbook_id($logbook_slug);
				if ($logbook_id != false) {
					// Get associated station locations for mysql queries
					$logbooks_locations_array = $this->logbooks_model->list_logbook_relationships($logbook_id);

					if (!$logbooks_locations_array) {
						// Logbook not found
						http_response_code(404);
						echo json_encode(['status' => 'failed', 'reason' => "Empty Logbook"]);
						die();
					}
				} else {
					// Logbook not found
					http_response_code(404);
					echo json_encode(['status' => 'failed', 'reason' => $logbook_slug . " has no associated station locations"]);
					die();
				}
				// Search Logbook for callsign
				$this->load->model('logbook_model');

				$result = $this->logbook_model->check_if_callsign_worked_in_logbook($callsign, $logbooks_locations_array, $band);

				http_response_code(201);
				if ($result > 0) {
					echo json_encode(['callsign' => $callsign, 'result' => 'Found']);
				} else {
					echo json_encode(['callsign' => $callsign, 'result' => 'Not Found']);
				}
			} else {
				// Logbook not found
				http_response_code(404);
				echo json_encode(['status' => 'failed', 'reason' => "logbook not found"]);
				die();
			}
		}
	}

	// API function to check if a grid is in the logbook already
	function logbook_check_grid()
	{
		header('Content-type: application/json');

		$this->load->model('api_model');

		// Decode JSON and store
		$obj = json_decode(file_get_contents("php://input"), true);
		if ($obj === NULL) {
			echo json_encode(['status' => 'failed', 'reason' => "wrong JSON"]);
		}

		if (!isset($obj['key']) || $this->api_model->authorize($obj['key']) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing api key"]);
		}

		if (!isset($obj['logbook_public_slug']) || !isset($obj['grid'])) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing fields"]);
			return;
		}

		if ($obj['logbook_public_slug'] != "" && $obj['grid'] != "") {

			$logbook_slug = $obj['logbook_public_slug'];
			$grid = $obj['grid'];

			// If $obj['band'] exists
			if (isset($obj['band'])) {
				$band = $obj['band'];
			} else {
				$band = null;
			}

			$this->load->model('logbooks_model');

			if ($this->logbooks_model->public_slug_exists($logbook_slug)) {
				$logbook_id = $this->logbooks_model->public_slug_exists_logbook_id($logbook_slug);
				if ($logbook_id != false) {
					// Get associated station locations for mysql queries
					$logbooks_locations_array = $this->logbooks_model->list_logbook_relationships($logbook_id);

					if (!$logbooks_locations_array) {
						// Logbook not found
						http_response_code(404);
						echo json_encode(['status' => 'failed', 'reason' => "Empty Logbook"]);
						die();
					}
				} else {
					// Logbook not found
					http_response_code(404);
					echo json_encode(['status' => 'failed', 'reason' => $logbook_slug . " has no associated station locations"]);
					die();
				}
				// Search Logbook for callsign
				$this->load->model('logbook_model');

				$result = $this->logbook_model->check_if_grid_worked_in_logbook($grid, $logbooks_locations_array, $band);

				http_response_code(201);
				if ($result > 0) {
					echo json_encode(['gridsquare' => strtoupper($grid), 'result' => 'Found']);
				} else {
					echo json_encode(['gridsquare' => strtoupper($grid), 'result' => 'Not Found']);
				}
			} else {
				// Logbook not found
				http_response_code(404);
				echo json_encode(['status' => 'failed', 'reason' => "logbook not found"]);
				die();
			}
		}
	}


	/**
	 * Check if a country has been worked before and confirmed in various ways
	 * 
	 * This API endpoint checks if a specific country (derived from callsign) has been
	 * worked before in a given logbook, and whether it has been confirmed via different
	 * confirmation methods (QSL, LoTW, eQSL, QRZ).
	 * 
	 * @api POST /api/logbook_check_country
	 * @header Content-Type application/json
	 * 
	 * @param string key Required. API authentication key
	 * @param string logbook_public_slug Required. Public slug identifier for the logbook
	 * @param string callsign Required. Callsign to lookup country for
	 * @param string type Optional. Type of contact ("sat" for satellite, empty for regular)
	 * @param string band Optional. Amateur radio band (required for non-satellite contacts)
	 * @param string mode Optional. Amateur radio mode (required for non-satellite contacts)
	 * 
	 * @return json Returns JSON object with:
	 *   - workedBefore: boolean indicating if country was worked before
	 *   - confirmed: object with confirmation status for qsl, lotw, eqsl, qrz
	 * 
	 * @throws 401 Unauthorized - Missing or invalid API key, missing required fields
	 * @throws 404 Not Found - Logbook not found or empty logbook
	 * @throws 400 Bad Request - Invalid JSON format
	 * 
	 * @example
	 * Request:
	 * {
	 *   "key": "your-api-key",
	 *   "logbook_public_slug": "my-logbook",
	 *   "callsign": "W1AW",
	 *   "band": "20M",
	 *   "mode": "SSB"
	 * }
	 * 
	 * Response:
	 * {
	 *   "workedBefore": true,
	 *   "confirmed": {
	 *     "qsl": true,
	 *     "lotw": false,
	 *     "eqsl": false,
	 *     "qrz": false
	 *   }
	 * }
	 */
	function logbook_check_country()
	{
		header('Content-type: application/json');

		$this->load->model('api_model');

		// Decode JSON and store
		$obj = json_decode(file_get_contents("php://input"), true);
		if ($obj === NULL) {
			echo json_encode(['status' => 'failed', 'reason' => "wrong JSON"]);
			return;
		}

		if (!isset($obj['key']) || $this->api_model->authorize($obj['key']) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing api key"]);
			return;
		}

		if (!isset($obj['logbook_public_slug']) || !isset($obj['callsign'])) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing fields"]);
			return;
		}

		// Load models
		$this->load->model('logbook_model');
		$this->load->model('logbooks_model');

		$date = date("Y-m-d");
		$callsign = $obj['callsign'];
		$logbook_slug = $obj['logbook_public_slug'];
		$type = isset($obj['type']) ? $obj['type'] : '';
		$band = isset($obj['band']) ? $obj['band'] : '';
		$mode = isset($obj['mode']) ? $obj['mode'] : '';

		$callsign_dxcc_lookup = $this->logbook_model->dxcc_lookup($callsign, $date);
		$country = $callsign_dxcc_lookup['entity'];

		$return = [
			"workedBefore" => false,
			"confirmed" => [
				"qsl" => false,
				"lotw" => false,
				"eqsl" => false,
				"qrz" => false
			]
		];

		if ($this->logbooks_model->public_slug_exists($logbook_slug)) {
			$logbook_id = $this->logbooks_model->public_slug_exists_logbook_id($logbook_slug);
			if ($logbook_id != false) {
				// Get associated station locations for mysql queries
				$logbooks_locations_array = $this->logbooks_model->list_logbook_relationships($logbook_id);

				if (!$logbooks_locations_array) {
					// Logbook not found
					http_response_code(404);
					echo json_encode(['status' => 'failed', 'reason' => "Empty Logbook"]);
					die();
				}
			} else {
				// Logbook not found
				http_response_code(404);
				echo json_encode(['status' => 'failed', 'reason' => $logbook_slug . " has no associated station locations"]);
				die();
			}

			if (!empty($logbooks_locations_array)) {
				if ($type == "sat") {
					$this->db->where('COL_PROP_MODE', 'SAT');
				} else {
					$this->db->where('COL_MODE', $this->logbook_model->get_main_mode_from_mode($mode));
					$this->db->where('COL_BAND', $band);
					$this->db->where('COL_PROP_MODE !=', 'SAT');
				}

				$this->db->where_in('station_id', $logbooks_locations_array);
				$this->db->where('COL_COUNTRY', urldecode($country));

				$query = $this->db->get($this->config->item('table_name'), 1, 0);
				foreach ($query->result() as $workedBeforeRow) {
					$return['workedBefore'] = true;
				}

				// Check each confirmation type separately
				$confirmation_types = [
					'qsl' => "COL_QSL_RCVD='Y'",
					'lotw' => "COL_LOTW_QSL_RCVD='Y'",
					'eqsl' => "COL_EQSL_QSL_RCVD='Y'",
					'qrz' => "COL_QRZCOM_QSO_DOWNLOAD_STATUS='Y'"
				];

				foreach ($confirmation_types as $type_key => $where_clause) {
					if ($type == "SAT") {
						$this->db->where('COL_PROP_MODE', 'SAT');
					} else {
						$this->db->where('COL_MODE', $this->logbook_model->get_main_mode_from_mode($mode));
						$this->db->where('COL_BAND', $band);
						$this->db->where('COL_PROP_MODE !=', 'SAT');
					}

					$this->db->where_in('station_id', $logbooks_locations_array);
					$this->db->where('COL_COUNTRY', urldecode($country));
					$this->db->where($where_clause);

					$query = $this->db->get($this->config->item('table_name'), 1, 0);

					if ($query->num_rows() > 0) {
						$return['confirmed'][$type_key] = true;
					}
				}

				http_response_code(201);
				echo json_encode($return, JSON_PRETTY_PRINT);
			} else {
				http_response_code(201);
				echo json_encode($return, JSON_PRETTY_PRINT);
			}
		} else {
			// Logbook not found
			http_response_code(404);
			echo json_encode(['status' => 'failed', 'reason' => "logbook not found"]);
			die();
		}
	}

	/* ENDPOINT for Rig Control */

	function radio()
	{
		header('Content-type: application/json');

		$this->load->model('api_model');

		//$json = '{"radio":"FT-950","frequency":14075,"mode":"SSB","timestamp":"2012/04/07 16:47"}';

		$this->load->model('cat');

		//var_dump(file_get_contents("php://input"), true);

		// Decode JSON and store
		$obj = json_decode(file_get_contents("php://input"), true);

		if (!isset($obj['key']) || $this->api_model->authorize($obj['key']) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => "missing api key"]);
			die();
		}

		$this->api_model->update_last_used($obj['key']);

		$user_id = $this->api_model->key_userid($obj['key']);

		// Store Result to Database
		$this->cat->update($obj, $user_id);

		// Return Message

		$arr = array('status' => 'success');

		echo json_encode($arr);
	}

	/*
	 * Radio Commands API Functions for Aurora Desktop App
	 */

	/**
	 * Get pending radio commands
	 * GET /api/radio_commands_pending/{key}
	 * GET /api/radio_commands_pending_by_name/{key}/{radio_name}
	 */
	function radio_commands_pending($key, $radio_id = null)
	{
		header('Content-type: application/json');
		
		$this->load->model('api_model');
		$this->load->model('cat');

		// Check API key
		if (!$key || $this->api_model->authorize($key) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => 'unauthorized']);
			return;
		}

		$this->api_model->update_last_used($key);
		$user_id = $this->api_model->key_userid($key);

		if ($radio_id) {
			// Get commands for specific radio by ID
			$commands = $this->cat->get_pending_commands($radio_id, $user_id);
		} else {
			// Get all pending commands for user
			$commands = $this->cat->get_all_pending_commands($user_id);
		}

		// Debug information
		$debug_info = array(
			'user_id' => $user_id,
			'radio_id' => $radio_id,
			'current_time' => date('Y-m-d H:i:s'),
			'total_commands_in_db' => $this->db->where('user_id', $user_id)->count_all_results('radio_commands'),
			'pending_commands_in_db' => $this->db->where('user_id', $user_id)->where('status', 'PENDING')->count_all_results('radio_commands')
		);

		echo json_encode([
			'status' => 'success',
			'commands' => $commands,
			'count' => count($commands),
			'debug' => $debug_info
		]);
	}

	/**
	 * Get pending radio commands by radio name (for Aurora)
	 * GET /api/radio_commands_pending_by_name/{key}/{radio_name}
	 */
	function radio_commands_pending_by_name($key, $radio_name = null)
	{
		header('Content-type: application/json');
		
		$this->load->model('api_model');
		$this->load->model('cat');

		// Check API key
		if (!$key || $this->api_model->authorize($key) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => 'unauthorized']);
			return;
		}

		$this->api_model->update_last_used($key);
		$user_id = $this->api_model->key_userid($key);

		// Clean up expired commands for this user
		$cleaned = $this->cat->cleanup_expired_commands($user_id);

		if ($radio_name) {
			// URL decode the radio name in case it has spaces/special chars
			$decoded_radio_name = urldecode($radio_name);
			
			// Additional cleanup - remove any extra whitespace
			$decoded_radio_name = trim($decoded_radio_name);
			
			// Debug: Check if radio exists in cat table for this user
			$radio_check = $this->db->select('id, radio, user_id')
				->where('radio', $decoded_radio_name)
				->where('user_id', $user_id)
				->get('cat');
			
			// Debug: Check all radios for this user
			$all_user_radios = $this->db->select('id, radio')
				->where('user_id', $user_id)
				->get('cat');
			
			// Debug: Check if any commands exist for this user
			$all_commands = $this->db->select('id, radio_id, radio_name, command_type, status, created_at, expires_at')
				->where('user_id', $user_id)
				->get('radio_commands');
			
			// Get commands for specific radio by name
			$commands = $this->cat->get_pending_commands_by_name($decoded_radio_name, $user_id);
			
			$debug_info = array(
				'user_id' => $user_id,
				'decoded_radio_name' => $decoded_radio_name,
				'radio_found_in_cat' => $radio_check->num_rows() > 0,
				'radio_details' => $radio_check->num_rows() > 0 ? $radio_check->row_array() : null,
				'all_user_radios' => $all_user_radios->result_array(),
				'all_user_commands' => $all_commands->result_array(),
				'current_time' => date('Y-m-d H:i:s')
			);
		} else {
			// Get all pending commands for user
			$commands = $this->cat->get_all_pending_commands($user_id);
			$decoded_radio_name = null;
			$debug_info = array('user_id' => $user_id);
		}

		echo json_encode([
			'status' => 'success',
			'commands' => $commands,
			'count' => count($commands),
			'radio_name' => $decoded_radio_name,
			'original_param' => $radio_name,
			'expired_cleaned' => $cleaned
		]);
	}

	/**
	 * Update radio command status
	 * POST /api/radio_commands/update_status/{key}
	 * Body: {"command_id": 123, "status": "COMPLETED", "error_message": "optional"}
	 */
	function radio_commands_update_status($key)
	{
		header('Content-type: application/json');
		
		$this->load->model('api_model');
		$this->load->model('cat');

		// Check API key
		if (!$key || $this->api_model->authorize($key) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => 'unauthorized']);
			return;
		}

		$this->api_model->update_last_used($key);
		$user_id = $this->api_model->key_userid($key);

		// Get JSON input
		$input = json_decode(file_get_contents("php://input"), true);

		if (!isset($input['command_id']) || !isset($input['status'])) {
			http_response_code(400);
			echo json_encode(['status' => 'failed', 'reason' => 'missing command_id or status']);
			return;
		}

		$command_id = $input['command_id'];
		$status = $input['status'];
		$error_message = isset($input['error_message']) ? $input['error_message'] : null;

		// Validate status
		$valid_statuses = ['PROCESSING', 'COMPLETED', 'FAILED'];
		if (!in_array($status, $valid_statuses)) {
			http_response_code(400);
			echo json_encode(['status' => 'failed', 'reason' => 'invalid status']);
			return;
		}

		// Verify command belongs to user
		$command = $this->cat->get_command($command_id, $user_id);
		if (!$command) {
			http_response_code(404);
			echo json_encode(['status' => 'failed', 'reason' => 'command not found']);
			return;
		}

		// Update command status
		$result = $this->cat->update_command_status($command_id, $status, $error_message);

		if ($result) {
			echo json_encode(['status' => 'success', 'updated' => true]);
		} else {
			http_response_code(500);
			echo json_encode(['status' => 'failed', 'reason' => 'update failed']);
		}
	}

	/**
	 * Get radio command by ID
	 * GET /api/radio_commands/get/{key}/{command_id}
	 */
	function radio_commands_get($key, $command_id)
	{
		header('Content-type: application/json');
		
		$this->load->model('api_model');
		$this->load->model('cat');

		// Check API key
		if (!$key || $this->api_model->authorize($key) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => 'unauthorized']);
			return;
		}

		$this->api_model->update_last_used($key);
		$user_id = $this->api_model->key_userid($key);

		$command = $this->cat->get_command($command_id, $user_id);

		if ($command) {
			echo json_encode([
				'status' => 'success',
				'command' => $command
			]);
		} else {
			http_response_code(404);
			echo json_encode(['status' => 'failed', 'reason' => 'command not found']);
		}
	}

	/**
	 * Queue a new radio command (for testing or external apps)
	 * POST /api/radio_commands_queue/{key}
	 * Body: {"radio_id": 1, "command_type": "SET_FREQ", "frequency": 14074000, "mode": "USB"}
	 * OR: {"radio_name": "FT-991A", "command_type": "SET_FREQ", "frequency": 14074000, "mode": "USB"}
	 */
	function radio_commands_queue($key)
	{
		header('Content-type: application/json');
		
		$this->load->model('api_model');
		$this->load->model('cat');

		// Check API key
		if (!$key || $this->api_model->authorize($key) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => 'unauthorized']);
			return;
		}

		$this->api_model->update_last_used($key);
		$user_id = $this->api_model->key_userid($key);

		// Get JSON input
		$input = json_decode(file_get_contents("php://input"), true);

		if (!isset($input['command_type'])) {
			http_response_code(400);
			echo json_encode(['status' => 'failed', 'reason' => 'missing command_type']);
			return;
		}

		$radio_id = null;

		// Check if radio_name is provided instead of radio_id
		if (isset($input['radio_name'])) {
			// Get radio ID from radio name
			$radio_query = $this->db->select('id')
				->where('radio', $input['radio_name'])
				->where('user_id', $user_id)
				->get('cat');

			if ($radio_query->num_rows() == 0) {
				http_response_code(404);
				echo json_encode(['status' => 'failed', 'reason' => 'radio not found']);
				return;
			}

			$radio_id = $radio_query->row()->id;

		} elseif (isset($input['radio_id'])) {
			$radio_id = $input['radio_id'];
		} else {
			http_response_code(400);
			echo json_encode(['status' => 'failed', 'reason' => 'missing radio_id or radio_name']);
			return;
		}

		// Prepare command data
		$command_data = array(
			'radio_id' => $radio_id,
			'user_id' => $user_id,
			'command_type' => $input['command_type'],
			'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes'))
		);

		// Add optional parameters
		if (isset($input['frequency'])) $command_data['frequency'] = $input['frequency'];
		if (isset($input['mode'])) $command_data['mode'] = $input['mode'];
		if (isset($input['vfo'])) $command_data['vfo'] = $input['vfo'];
		if (isset($input['power'])) $command_data['power'] = $input['power'];
		if (isset($input['station_id'])) $command_data['station_id'] = $input['station_id'];

		$command_id = $this->cat->queue_command($command_data);

		if ($command_id) {
			echo json_encode([
				'status' => 'success',
				'command_id' => $command_id,
				'message' => 'Command queued successfully'
			]);
		} else {
			http_response_code(500);
			echo json_encode(['status' => 'failed', 'reason' => 'failed to queue command']);
		}
	}

	/**
	 * Get all pending radio commands for all user's radios (optimized for Aurora)
	 * GET /api/radio_commands_all_pending/{key}
	 */
	function radio_commands_all_pending($key)
	{
		header('Content-type: application/json');
		
		$this->load->model('api_model');
		$this->load->model('cat');

		// Check API key
		if (!$key || $this->api_model->authorize($key) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => 'unauthorized']);
			return;
		}

		$this->api_model->update_last_used($key);
		$user_id = $this->api_model->key_userid($key);

		// Clean up expired commands for this user
		$cleaned = $this->cat->cleanup_expired_commands($user_id);

		// Get all pending commands for all user's radios
		$commands = $this->cat->get_all_pending_commands($user_id);

		// Group commands by radio for easier processing
		$commands_by_radio = array();
		$radio_names = array();

		foreach ($commands as $command) {
			$radio_name = $command['radio_name'];
			if (!isset($commands_by_radio[$radio_name])) {
				$commands_by_radio[$radio_name] = array();
				$radio_names[] = $radio_name;
			}
			$commands_by_radio[$radio_name][] = $command;
		}

		echo json_encode([
			'status' => 'success',
			'commands' => $commands,
			'commands_by_radio' => $commands_by_radio,
			'radio_names' => $radio_names,
			'total_commands' => count($commands),
			'radios_with_commands' => count($commands_by_radio),
			'expired_cleaned' => $cleaned,
			'timestamp' => date('Y-m-d H:i:s')
		]);
	}

	/**
	 * Cleanup expired radio commands (maintenance endpoint)
	 * POST /api/radio_commands_cleanup/{key}
	 * Body: {"cleanup_old": true, "days_old": 7} (optional parameters)
	 */
	function radio_commands_cleanup($key)
	{
		header('Content-type: application/json');
		
		$this->load->model('api_model');
		$this->load->model('cat');

		// Check API key
		if (!$key || $this->api_model->authorize($key) == 0) {
			http_response_code(401);
			echo json_encode(['status' => 'failed', 'reason' => 'unauthorized']);
			return;
		}

		$this->api_model->update_last_used($key);
		$user_id = $this->api_model->key_userid($key);

		// Get optional JSON input
		$input = json_decode(file_get_contents("php://input"), true);
		$cleanup_old = isset($input['cleanup_old']) ? $input['cleanup_old'] : false;
		$days_old = isset($input['days_old']) ? intval($input['days_old']) : 7;

		// Clean up expired commands
		$expired_cleaned = $this->cat->cleanup_expired_commands($user_id);
		
		$old_cleaned = 0;
		if ($cleanup_old) {
			$old_cleaned = $this->cat->cleanup_old_commands($days_old, $user_id);
		}

		echo json_encode([
			'status' => 'success',
			'expired_commands_cleaned' => $expired_cleaned,
			'old_commands_cleaned' => $old_cleaned,
			'cleanup_old_enabled' => $cleanup_old,
			'days_old_threshold' => $days_old,
			'timestamp' => date('Y-m-d H:i:s')
		]);
	}

	/*
	*
	*	Stats API function calls
	*
	*/

	function statistics($key = null)
	{
		header('Content-type: application/json');
		$this->load->model('logbook_model');

		$data['todays_qsos'] = $this->logbook_model->todays_qsos(null, $key);
		$data['total_qsos'] = $this->logbook_model->total_qsos(null, $key);
		$data['month_qsos'] = $this->logbook_model->month_qsos(null, $key);
		$data['year_qsos'] = $this->logbook_model->year_qsos(null, $key);

		http_response_code(201);
		echo json_encode(['Today' => $data['todays_qsos'], 'total_qsos' => $data['total_qsos'], 'month_qsos' => $data['month_qsos'], 'year_qsos' => $data['year_qsos']]);
	}

	function lookup()
	{
		// start benchmarking
		$this->output->enable_profiler(TRUE);
		/*
		*
		*	Callsign lookup function for Cloudlogs logging page or thirdparty systems
		*	which want to show previous QSO data on their system.
		*
		*	TODO
		*	- Local data make one database call ONLY
		*	- Add eQSL status
		*	- Add Callbook returned data
		*	- Add QSO before data array
		*	- Add options for checking based on band/mode/sat
		*
		*/


		// Make sure users logged in
		$this->load->model('user_model');
		if (!$this->user_model->authorize($this->config->item('auth_mode'))) {
			return;
		}


		$this->load->model("logbook_model");
		$date = date("Y-m-d");

		// Return Array
		$return = [
			"callsign" => "",
			"dxcc" => false,
			"dxcc_lat" => "",
			"dxcc_long" => "",
			"dxcc_cqz" => "",
			"name" => "",
			"gridsquare"  => "",
			"location"  => "",
			"iota_ref" => "",
			"state" => "",
			"us_county" => "",
			"qsl_manager" => "",
			"bearing" 		=> "",
			"workedBefore" => false,
			"lotw_member" => false,
			"suffix_slash" => "", // Suffix Slash aka Portable
		];


		/*
		*
		*	Handle POST data being sent to check lookups
		*
		*/
		$raw_input = json_decode(file_get_contents("php://input"), true);

		$lookup_callsign = strtoupper($raw_input['callsign']);


		/*
		*
		*	Handle Callsign field
		*
		*/
		$return['callsign'] = $lookup_callsign;

		/*
		*
		*	Lookup DXCC and Suffix information
		*
		*/

		$callsign_dxcc_lookup = $this->logbook_model->dxcc_lookup($lookup_callsign, $date);

		$last_slash_pos = strrpos($lookup_callsign, '/');

		if (isset($last_slash_pos) && $last_slash_pos > 4) {
			$suffix_slash = $last_slash_pos === false ? $lookup_callsign : substr($lookup_callsign, $last_slash_pos + 1);
			switch ($suffix_slash) {
				case "P":
					$suffix_slash_item = "Portable";
					break;
				case "M":
					$suffix_slash_item = "Mobile";
				case "MM":
					$suffix_slash_item =  "Maritime Mobile";
					break;
				default:
					// If its not one of the above suffix slashes its likely dxcc
					$ans2 = $this->logbook_model->dxcc_lookup($suffix_slash, $date);
					$suffix_slash_item = null;
			}

			$return['suffix_slash'] = $suffix_slash_item;
		}

		// If the final slash is a DXCC then find it!
		if (isset($ans2['call'])) {
			$return['dxcc'] = $ans2['entity'];
			$return['dxcc_lat'] = $ans2['lat'];
			$return['dxcc_long'] = $ans2['long'];
			$return['dxcc_cqz'] = $ans2['cqz'];
		} else {
			$return['dxcc'] = $callsign_dxcc_lookup['entity'];
			$return['dxcc_lat'] = $callsign_dxcc_lookup['lat'];
			$return['dxcc_long'] = $callsign_dxcc_lookup['long'];
			$return['dxcc_cqz'] = $callsign_dxcc_lookup['cqz'];
		}

		/*
		*
		*	Pool any local data we have for a callsign
		*
		*/
		$call_lookup_results = $this->logbook_model->call_lookup_result($lookup_callsign);

		if ($call_lookup_results != null) {
			$return['name'] = $call_lookup_results->COL_NAME;
			$return['gridsquare'] = $call_lookup_results->COL_GRIDSQUARE;
			$return['location'] = $call_lookup_results->COL_QTH;
			$return['iota_ref'] = $call_lookup_results->COL_IOTA;
			$return['qsl_manager'] = $call_lookup_results->COL_QSL_VIA;
			$return['state'] = $call_lookup_results->COL_STATE;
			$return['us_county'] = $call_lookup_results->COL_CNTY;

			if ($return['gridsquare'] != "") {
				$return['latlng'] = $this->qralatlng($return['gridsquare']);
			}
		}


		/*
		*
		*	Check if callsign is active on LoTW
		*
		*/


		/*
		*
		*	Output Returned data
		*
		*/
		echo json_encode($return, JSON_PRETTY_PRINT);
		return;

		// End benchmarking
		$this->output->enable_profiler(FALSE);
	}

	function qralatlng($qra)
	{
		$this->load->library('Qra');
		$latlng = $this->qra->qra2latlong($qra);
		return $latlng;
	}

	/**
	 * API endpoint to get recent QSOs from a public logbook
	 * 
	 * @api GET /api/recent_qsos/{public_slug}/{limit}
	 * 
	 * @param string public_slug Required. Public slug identifier for the logbook
	 * @param int limit Optional. Number of QSOs to return (default: 10, max: 50)
	 * 
	 * @return json Returns JSON array with recent QSO data or error message
	 * 
	 * @throws 404 Not Found - Logbook not found or empty logbook
	 * @throws 400 Bad Request - Invalid limit parameter
	 * 
	 * @example
	 * Request: GET /api/recent_qsos/my-public-logbook/5
	 * 
	 * Response:
	 * {
	 *   "qsos": [
	 *     {
	 *       "date": "2024-01-15",
	 *       "time": "14:30",
	 *       "callsign": "W1AW",
	 *       "mode": "SSB",
	 *       "band": "20M",
	 *       "rst_sent": "59",
	 *       "rst_rcvd": "59"
	 *     }
	 *   ],
	 *   "count": 1,
	 *   "logbook_slug": "my-public-logbook"
	 * }
	 */
	function recent_qsos($public_slug = null, $limit = 10)
	{
		header('Content-type: application/json');

		// Validate and sanitize $limit
		if (!is_numeric($limit)) {
			$limit = 10; // Default to 10 if not numeric
		} else {
			$limit = intval($limit);
			if ($limit < 1) {
				$limit = 1; // Minimum limit of 1
			} elseif ($limit > 50) {
				$limit = 50; // Maximum limit of 50
			}
		}

		if ($public_slug == null) {
			http_response_code(400);
			echo json_encode(['status' => 'failed', 'reason' => 'missing public_slug parameter']);
			return;
		}

		$this->load->model('logbooks_model');
		$this->load->model('logbook_model');

		if ($this->logbooks_model->public_slug_exists($public_slug)) {
			$logbook_id = $this->logbooks_model->public_slug_exists_logbook_id($public_slug);
			if ($logbook_id != false) {
				// Get associated station locations for mysql queries
				$logbooks_locations_array = $this->logbooks_model->list_logbook_relationships($logbook_id);

				if (!$logbooks_locations_array) {
					http_response_code(404);
					echo json_encode(['status' => 'failed', 'reason' => 'Empty Logbook']);
					return;
				}

				// Get recent QSOs using existing method
				$recent_qsos_query = $this->logbook_model->get_last_qsos($limit, $logbooks_locations_array);

				if ($recent_qsos_query == null) {
					http_response_code(404);
					echo json_encode(['status' => 'failed', 'reason' => 'No QSOs found']);
					return;
				}

				// Format the data for JSON response
				$qsos = array();
				foreach ($recent_qsos_query->result() as $row) {
					$qso = array(
						'date' => date('Y-m-d', strtotime($row->COL_TIME_ON)),
						'time' => date('H:i', strtotime($row->COL_TIME_ON)),
						'callsign' => strtoupper($row->COL_CALL),
						'mode' => $row->COL_SUBMODE ? $row->COL_SUBMODE : $row->COL_MODE,
						'band' => $row->COL_SAT_NAME ? $row->COL_SAT_NAME : $row->COL_BAND,
						'rst_sent' => $row->COL_RST_SENT,
						'rst_rcvd' => $row->COL_RST_RCVD
					);

					// Add optional fields if they exist
					if ($row->COL_STX_STRING) {
						$qso['stx_string'] = $row->COL_STX_STRING;
					}
					if ($row->COL_SRX_STRING) {
						$qso['srx_string'] = $row->COL_SRX_STRING;
					}
					if ($row->COL_GRIDSQUARE) {
						$qso['gridsquare'] = $row->COL_GRIDSQUARE;
					}
					if ($row->COL_QTH) {
						$qso['qth'] = $row->COL_QTH;
					}
					if ($row->COL_NAME) {
						$qso['name'] = $row->COL_NAME;
					}

					$dxcc = $this->logbook_model->check_dxcc_table(strtoupper(trim(strtoupper($row->COL_CALL))), $row->COL_TIME_ON);
					if (empty($dxcc[0])) {
						$dxcc_id = null;
					} else {
						$qso['country'] = $dxcc[1];
						$qso['lat'] = $dxcc[4];
						$qso['long'] = $dxcc[5];
					}

					$qsos[] = $qso;
				}

				http_response_code(200);
				echo json_encode([
					'qsos' => $qsos,
					'count' => count($qsos),
					'logbook_slug' => $public_slug
				], JSON_PRETTY_PRINT);
			} else {
				http_response_code(404);
				echo json_encode(['status' => 'failed', 'reason' => $public_slug . ' has no associated station locations']);
			}
		} else {
			http_response_code(404);
			echo json_encode(['status' => 'failed', 'reason' => 'logbook not found']);
		}
	}
}
