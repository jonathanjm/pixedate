<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . '/libraries/REST_Controller.php';
require_once APPPATH . '/libraries/JWT.php';
require_once APPPATH . '/libraries/Mailin.php';
require_once APPPATH . '/libraries/stripe/init.php';

use Restserver\Libraries\REST_Controller;
use \Firebase\JWT\JWT;

class Dates extends REST_Controller {


    public function __construct() {
        parent:: __construct();
    }

    //return first_name, image, bio, id
    //age, longitude, latitude, distance, interested_in, gender
    public function search_post(){

        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $this->load->library('form_validation');
        $this->form_validation->set_rules("latitude", "Latitude", "trim|required");
        $this->form_validation->set_rules("longitude", "Longitude", "trim|required");

        if($this->form_validation->run()){

            $query = $this->db->select('users.id, users.dob,preferences.longitude,
            preferences.latitude,preferences.distance,preferences.age_interested_start,
            preferences.age_interested_end,preferences.gender,preferences.interested_in')
            ->from('users')
            ->join('preferences', 'users.id = preferences.user_id','left')
            ->where('users.id',$token->id)
            ->get();

            $user = $query->row();

            $resultsQuery = $this->db->select("users.first_name, users.bio, users.id, users.image,
             (
                    6371 *
                    acos(
                        cos( radians(".$this->post('latitude').") ) *
                        cos( radians( preferences.latitude ) ) *
                        cos(
                            radians( preferences.longitude ) - radians(".$this->post('longitude').")
                        ) +
                        sin(radians(".$this->post('latitude').")) *
                        sin(radians(preferences.latitude))
                    )
                ) userDistance
            ")
            ->from('users')
            ->join('preferences', 'users.id = preferences.user_id','left')
            ->join('pool', 'users.id = pool.match_id','left')
            ->where('users.id !=',$token->id)
            ->where('pool.match_id IS NULL')
            ->where('preferences.gender',$user->interested_in)
            ->where('TIMESTAMPDIFF(YEAR, users.dob, CURDATE()) >= '.$user->age_interested_start.' AND TIMESTAMPDIFF(YEAR, users.dob, CURDATE()) <= '.$user->age_interested_end)
            ->having('userDistance <='.$user->distance)
            ->order_by('preferences.distance','asc')
            ->limit(25)->get();

            $results = $resultsQuery->result_array();

            return $this->response([
                'status' => TRUE,
                'result' => $results
                ], REST_Controller::HTTP_OK
            );

        }else{

            return $this->response([
                'status' => FALSE,
                'errors' => $this->form_validation->error_array(),
                ], REST_Controller::HTTP_OK
            );
        }

    }

    public function add_to_pool_post(){
        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $this->load->library('form_validation');
        $this->form_validation->set_rules("user_id", "User Id", "trim|required");

        if($this->form_validation->run()){

            $data = array(
                'user_id' => $token->id,
                'match_id' => $this->post('user_id')
            );
            $this->db->insert('pool', $data);

            $query = $this->db->select('pool.id')->from('pool')->get();
            $rowcount = $query->num_rows();

            if($rowcount >= 10){
                $min_pool = TRUE;
            }else{
                $min_pool = FALSE;
            }

            return $this->response([
                'status' => TRUE,
                'min_pool' => $min_pool
                ], REST_Controller::HTTP_OK
            );
        }else{
            return $this->response([
                'status' => FALSE,
                'errors' => $this->form_validation->error_array(),
                ], REST_Controller::HTTP_OK
            );
        }
    }

    public function find_match_step1_post(){

        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        //holding button
        $this->db->where('id',$token->id);
        $this->db->update('users', array('holding' => 1));

        $query = $this->db->select('*')
        ->from('pool')
        ->join('users', 'pool.match_id = users.id')
        ->where('pool.user_id', $token->id)
        ->where('users.holding',1)
        ->order_by('holding_stamp','desc')
        ->limit(1)->get();

        $match = $query->row();

        if($match->id){
            $this->response([
                'status' => true,
                'user' =>$match->id,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $this->response([
            'status' => false,
        ], REST_Controller::HTTP_UNAUTHORIZED);

    }

        //Todo
        //flag that user is holding the button down
        //make sure possible match is not on a date


        /**
         * get matches in the pool
         * get the first one holding a button
         * if true, return user_id and send silent push to match holding the button down. Remove flag for both.
         */
/*
        Pixedate find a match sequence 

            •	call find a match
            ◦	search for match holding the button
            ◦	if yes: 
            ▪	return match 
            ◦	if no:
            ▪	wait 10 secs
            ▪	if still no:
            ▪	check if a match is on the app but not holding a button
            ▪	if yes:
            ▪	send push notification(s)
            ▪	wait 10 sec for a response 
            ▪	if no response or all say no: send push

            find_match_step1()
            find_match_step2()
            find_match_step3()
*/


    public function find_match_step2_post(){
        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $query = $this->db->select('*')
        ->from('pool')
        ->join('users', 'pool.match_id = users.id')
        ->where('pool.user_id', $token->id)
        ->where('users.status',1)
        ->where('users.holding',0)
        ->get();

        $matches = $query->result_array();

        if(!empty($matches)){

            foreach($matches as $match){

                $this->sendMessage('no message',$match->email,$match->id);
            }

            $this->response([
                'status' => true,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $this->response([
            'status' => false,
        ], REST_Controller::HTTP_UNAUTHORIZED);

    }

    public function find_match_step3_post(){

        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $query = $this->db->select('*')
        ->from('pool')
        ->join('users', 'pool.match_id = users.id')
        ->where('pool.user_id', $token->id)
        ->where('users.status',0)
        ->where('users.holding',0)
        ->get();

        $matches = $query->result_array();

        if(!empty($matches)){

            foreach($matches as $match){

                $this->sendMessage('no message',$match->email,$match->id);
            }

            $this->response([
                'status' => true,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $this->response([
            'status' => false,
        ], REST_Controller::HTTP_UNAUTHORIZED);
    }


    //on button release, call this function
    public function find_match_end_post(){
        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

                if(!$token->id){
                    $this->response([
                        'status' => false,
                    ], REST_Controller::HTTP_UNAUTHORIZED);
                }

        //holding button
        $this->db->where('id',$token->id);
        $this->db->update('users', array('holding' => 0));

        return $this->response([
            'status' => TRUE,
            ], REST_Controller::HTTP_OK
        );
    }

    function sendMessage($pushMessage,$email,$user_id){
		$content = array(
            "en" => $pushMessage
            );

		$fields = array(
			'app_id' => ONE_SIGNAL_APP_ID,
			'included_segments' => array('All'),
            'data' => array("user_id" => $user_id),
            'filters' => array("field" => "tag","key"=>"email","value"=>$email),
            "content_available" => true,
			'contents' => $content
		);

		$fields = json_encode($fields);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8',
												   'Authorization: Basic YjI4NTZjYWItZTI0Yi00YTQ0LWFjYTAtMzU3MmU3YjkzNmE2'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

		$response = curl_exec($ch);
		curl_close($ch);

		return $response;
	}

}
