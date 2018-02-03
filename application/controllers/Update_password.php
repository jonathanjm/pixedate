<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Update_password extends CI_Controller {

	public function index()
	{
		$this->load->view('update_password');
	}
}