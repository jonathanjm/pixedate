<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . '/libraries/REST_Controller.php';
require_once APPPATH . '/libraries/JWT.php';
require_once APPPATH . '/libraries/Mailin.php';
require_once APPPATH . '/libraries/stripe/init.php';
require_once APPPATH . '/libraries/aws/aws-autoloader.php';

use Restserver\Libraries\REST_Controller;
use \Firebase\JWT\JWT;

class User extends REST_Controller {


    public function __construct() {
        parent:: __construct();
    }

    //api for website
    public function business_email_signups_post(){
        $this->load->library('form_validation');
        $this->form_validation->set_rules("name", "Name", "trim|required");
        $this->form_validation->set_rules("role", "Role", "trim|required");
        $this->form_validation->set_rules("company", "Company Name", "trim|required");
        $this->form_validation->set_rules("email", "Email", "trim|required|valid_email|is_unique[business_email_signups.email]");

        $this->form_validation->set_message('required', '1');
        $this->form_validation->set_message('valid_email', '2');
        $this->form_validation->set_message('is_unique', '3');
        //validate form
        if($this->form_validation->run()){
            $data = array(
                'name' => $this->post('name'),
                'role' => $this->post('role'),
                'company' => $this->post('company'),
                'email' => $this->post('email')
            );
            $this->db->insert('business_email_signups', $data);

            $mailin = new Mailin("https://api.sendinblue.com/v2.0",SENDINBLUE);
            $email_data = array( "id" => 1,
                "to" => $this->post('email'),
                "headers" => array("Content-Type"=> "text/html;charset=iso-8859-1",
                                    "X-param1"=> "value1","X-Mailin-custom"=>"my custom value",
                                    "X-Mailin-tag"=>"my tag value")
            );

            $mailin->send_transactional_template($email_data);

            $this->response([
                'status' => TRUE,
                ], REST_Controller::HTTP_OK
            );

        }else{

            $this->response([
                'status' => FALSE,
                'errors' => $this->form_validation->error_array(),
                ], REST_Controller::HTTP_OK
            );
        }
    }

    //api for website
    public function email_signups_post(){

        $this->load->library('form_validation');
	    $this->form_validation->set_rules("email", "Email", "trim|required|valid_email|is_unique[email_signups.email]");

        $this->form_validation->set_message('required', '1');
        $this->form_validation->set_message('valid_email', '2');
        $this->form_validation->set_message('is_unique', '3');

	    if($this->form_validation->run()){

	        $data = array(
                'email' => $this->post('email')
            );

            $this->db->insert('email_signups', $data);

            $mailin = new Mailin("https://api.sendinblue.com/v2.0",SENDINBLUE);
            $email_data = array(
                "id" => 2,
                "to" => $this->post('email'),
                "headers" => array("Content-Type"=> "text/html;charset=iso-8859-1",
                                   "X-param1"=> "value1","X-Mailin-custom"=>"my custom value",
                                   "X-Mailin-tag"=>"my tag value")
            );

            $mailin->send_transactional_template($email_data);

            $this->response([
                'status' => TRUE,
                ], REST_Controller::HTTP_OK
            );

	    }else{

              $this->response([
                  'status' => FALSE,
                  'errors' => $this->form_validation->error_array(),
                ], REST_Controller::HTTP_OK
            );
	    }
    }

    public function signup_post(){

        $this->load->library('form_validation');
        $this->form_validation->set_rules("email", "Email", "trim|required|valid_email|is_unique[users.email]");
        $this->form_validation->set_rules("password", "Password", "trim|required");
        $this->form_validation->set_rules("first_name", "First Name", "trim|required");
        $this->form_validation->set_rules("last_name", "Last Name", "trim|required");
        $this->form_validation->set_rules("dob", "Date of Birth", "trim|required");

        //validate form
        if($this->form_validation->run()){

            $encrypted_password = password_hash($this->post('password'), PASSWORD_DEFAULT);
            $data = array(
                'email' => $this->post('email'),
                'password' => $encrypted_password,
                'first_name' => $this->post('first_name'),
                'last_name' => $this->post('last_name'),
                'dob'=> $this->post('dob')
            );
            $this->db->insert('users', $data);
            $user_id = $this->db->insert_id();

            $preferences = array(
                'user_id' => $user_id,
                'gender' => $this->post('gender'),
                'interested_in' => $this->post('interested_in'),
                'date_day' => 'today',
                'date_time' => 'afternoon,evening',
                'age_interested_start' => 18,
                'age_interested_end' => 35,
                'distance' => 20
            );

            $this->db->insert('preferences',$preferences);

            //create jwt token
            $date = new DateTime();
            $token['iat'] = $date->getTimestamp();
            $token['exp'] = strtotime('+2 years', $date->getTimestamp());
            $token['id'] = $user_id;
            $token_id = JWT::encode($token, "nuncalosabras");

            //update user status
            $this->db->where('id',$user_id);
            $this->db->update('users', array('status' => 1));

            $this->response([
                'status' => TRUE,
                'token' => $token_id
                ], REST_Controller::HTTP_OK
            );

        }else{

            $this->response([
                'status' => FALSE,
                'errors' => $this->form_validation->error_array(),
                ], REST_Controller::HTTP_OK
            );
        }
    }

    public function login_post(){

        $this->load->library('form_validation');
        $this->form_validation->set_rules("email", "Email", "trim|required|valid_email");
        $this->form_validation->set_rules("password", "Password", "trim|required");

        if($this->form_validation->run()){

            $query = $this->db->select('id,first_name,last_name,email,password')
            ->from('users')
            ->where('email', $this->post('email'))->get();

            $user = $query->row();

            if($user->email){
                if(password_verify($this->post('password'), $user->password)) {
                    $date = new DateTime();
                    $token['iat'] = $date->getTimestamp();
                    $token['exp'] = strtotime('+2 years', $date->getTimestamp());
                    $token['id'] = $user->id;
                    $token_id = JWT::encode($token, "nuncalosabras");

                    //update user status
                    $this->db->where('id',$user->id);
                    $this->db->update('users', array('status' => 1));

                    $this->response([
                            'status' => TRUE,
                            'token' => $token_id,
                            'user_id' => $user->id
                    ], REST_Controller::HTTP_OK);
                }else{
                    $this->response([
                        'status' => FALSE,
                    ], REST_Controller::HTTP_OK);
                }
            }else{
                $this->response([
                    'status' => FALSE,
                ], REST_Controller::HTTP_OK);
            }
        }else{

            $this->response([
                'status' => FALSE,
                'errors' => $this->form_validation->error_array(),
                ], REST_Controller::HTTP_OK
            );
        }
    }

    public function forgot_password_post(){
        $this->load->library('form_validation');
        $this->load->library('encryption');

        $this->form_validation->set_rules("email", "Email", "trim|required|valid_email");

        if($this->form_validation->run()){

            $query = $this->db->select('id')
            ->from('users')
            ->where('email', $this->post('email'))->get();

            $user = $query->row();

            if(!$user->id){
                return $this->response([
                    'status' => FALSE,
                    'message' => 'Email does not exist'
                ], REST_Controller::HTTP_OK
                );
            }

            $encryptedEmail = $this->encryption->encrypt($this->post('email'));
            $link = "http://api.pixedate.com/update_password/".$encryptedEmail;
            $mailin = new Mailin("https://api.sendinblue.com/v2.0",SENDINBLUE);
            $data = array( "id" => 3,
                "to" => $this->post('email'),
                "attr" => array("LINK" => $link),
                "headers" => array("Content-Type"=> "text/html;charset=iso-8859-1")
            );

            $mailin->send_transactional_template($data);

            return $this->response([
                    'status' => TRUE,
                ], REST_Controller::HTTP_OK
            );
        }else{
            return $this->response([
                    'status' => FALSE,
                ], REST_Controller::HTTP_OK
            );
        }
    }

    public function new_password_post(){

        $this->load->library('form_validation');
        $this->load->library('encryption');

        $this->form_validation->set_rules("hash", "Hash", "trim|required");
        $this->form_validation->set_rules("password", "Password", "trim|required");

        if($this->form_validation->run()){

            $email = $this->encryption->decrypt($this->post('hash'));

            $newPassword = password_hash($this->post('password'), PASSWORD_DEFAULT);

            $this->db->where('email',$email);
            $this->db->update('users', array('password' => $newPassword));

            if($this->db->affected_rows() > 0){
                return $this->response([
                    'status' => TRUE
                    ], REST_Controller::HTTP_OK
                );
            }else{
                return $this->response([
                    'status' => FALSE
                    ], REST_Controller::HTTP_OK
                );
            }

        }else{

            return $this->response([
                'status' => FALSE,
                'errors' => $this->form_validation->error_array(),
                ], REST_Controller::HTTP_OK
            );

        }
    }

    public function update_password_post(){
        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $this->load->library('form_validation');
        $this->form_validation->set_rules("password", "Password", "trim|required");
        $this->form_validation->set_rules("new_password", "New Password", "trim|required");

        if($this->form_validation->run()){

            $query = $this->db->select('id,first_name,last_name,email,password')
            ->from('users')
            ->where('id', $token->id)->get();

            $user = $query->row();

            if(password_verify($this->post('password'), $user->password)) {

                $newPassword = password_hash($this->post('new_password'), PASSWORD_DEFAULT);
                $this->db->where('id',$token->id);
                $this->db->update('users', array('password' => $newPassword));

                $this->response([
                    'status' => TRUE
                    ], REST_Controller::HTTP_OK
                );

            }else{
                return $this->response([
                        'status' => FALSE,
                    ], REST_Controller::HTTP_OK
                );
            }

        }else{

            return $this->response([
                'status' => FALSE,
                'errors' => $this->form_validation->error_array(),
                ], REST_Controller::HTTP_OK
            );

        }
    }

    public function update_preferences_post(){

        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $data = array(
            'interested_in' => $this->post('interested_in'),
            'date_day' => $this->post('date_day'),
            'date_time' => $this->post('date_time'),
            'age_interested_start' => $this->post('age_interested_start'),
            'age_interested_end' => $this->post('age_interested_end'),
            'distance' => $this->post('distance')
        );

        $this->db->where("user_id",$token->id);

        $this->db->update('preferences', $data);

        return $this->response([
            'status' => TRUE,
            ], REST_Controller::HTTP_OK
        );
    }

    public function update_coords_post(){
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
            $preferences = array(
                'latitude' => $this->post('latitude'),
                'longitude' => $this->post('longitude')
            );
            $this->db->where("user_id",$token->id)->update('preferences', $preferences);

            return $this->response([
                'status' => TRUE,
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

    public function update_dp_post(){
        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $this->load->library('form_validation');
        $this->form_validation->set_rules("image", "Image", "required");

        if($this->form_validation->run()){

            $config['upload_path']          = './uploads/';
            $config['allowed_types']        = 'gif|jpg|png';
            $config['max_size']             = 100;
            $config['max_width']            = 1024;
            $config['max_height']           = 768;

            $this->load->library('upload', $config);

            $this->upload->do_upload('image');

            // Configure a client using Spaces
            $client = new Aws\S3\S3Client([
                    'version' => 'latest',
                    'region'  => 'nyc3',
                    'endpoint' => 'https://nyc3.digitaloceanspaces.com',
                    'credentials' => [
                            'key'    => AWS_SECRET,
                            'secret' => AWS_KEY,
                        ],
            ]);
            $key = $token->id . '-' . time() . '.jpg';

            $insert = $client->putObject([
                 'Bucket' => 'pixedate',
                 'Key'    => $key,
                 'Body'   => $this->post('image'),
                 'ACL'    => 'public-read'
            ]);

            $data = array(
                'image' => $key
            );
            $this->db->where("id",$token->id)->update('users', $data);

            return $this->response([
                'status' => TRUE,
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

    public function update_bio_post(){
        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $data = array(
            'bio' => $this->post('bio')
        );

        $this->db->where("id",$token->id);

        $this->db->update('users', $data);

        return $this->response([
            'status' => TRUE,
            ], REST_Controller::HTTP_OK
        );

    }
    public function index_get(){

        $token = JWT::decode($_GET['token'], 'nuncalosabras', array('HS256'));

        if(!$token->id){
            $this->response([
                'status' => false,
            ], REST_Controller::HTTP_UNAUTHORIZED);
        }

        $query = $this->db->select('users.id as id,
        users.first_name,users.last_name,users.email,users.image,users.dob,users.bio,
        preferences.gender,preferences.interested_in,preferences.date_day,
        preferences.date_time,preferences.age_interested_start,preferences.age_interested_end,
        preferences.distance')
        ->from('users')
        ->join('preferences', 'users.id = preferences.user_id','left')
        ->where('users.id', $token->id)->get();

        $user = $query->row();

        if($user->id){
                $this->response([
                'status' => TRUE,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'image' => $user->image,
                'gender' => $user->gender,
                'interested_in' => $user->interested_in,
                'date_day' => $user->date_day,
                'date_time' => $user->date_time,
                'age_interested_start' => $user->age_interested_start,
                'age_interested_end' => $user->age_interested_end,
                'dob' => $user->dob,
                'bio' => $user->bio,
                'distance' => $user->distance
            ], REST_Controller::HTTP_OK);
        }

    }

    public function toggle_status_post(){
        $token = JWT::decode($_POST['token'], 'nuncalosabras', array('HS256'));

            if(!$token->id){
                $this->response([
                    'status' => false,
                ], REST_Controller::HTTP_UNAUTHORIZED);
            }

        //update user status
        $this->db->where('id',$token->id);
        $this->db->update('users', array('status' => $this->post('status')));

        return $this->response([
            'status' => TRUE,
            ], REST_Controller::HTTP_OK
        );

    }

}
