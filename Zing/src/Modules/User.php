<?php

namespace Modules;

class User extends Module{

    /**
     * Creates a salt that you can save into your datatabse
     * @param string $password The password to generate a salt from
     * @return string
     */
    public function createPassword($password){
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verifies that the password and salt can regenerate the salt
     * @param string $password The password a user gives such as from a form
     * @param string $salt The salt that was created from self::create()
     * @return boolean
     */
    public function verifyPassword($password, $salt){
        return password_verify($password, $salt);
    }

    /**
     * Sets session data for a particular user
     * @param array $data
     */
    public function login($data){
        foreach($data as $key => $val){
            $_SESSION[$key] = $val;
        }
        $_SESSION["ZingLoggedIn"] = true;
        return $this;
    }

    public function logout(){
        $_SESSION = array();
        session_destroy();
        return $this;
    }

    /**
     * Checks to see if a user is currently logged in
     * @return boolean
     */
    public function isLogged(){
        if(isset($_SESSION["ZingLoggedIn"]) && $_SESSION["ZingLoggedIn"]){
            return $_SESSION["ZingLoggedIn"];
        }
        return false;
    }

    /**
     * Forces user to be logged otherwise redirect to another page.
     * @param string $location
     */
    public function requireLogin($location){
        if(!$this->isLogged()){
            header("Location: $location");
            exit;
        }
    }

}
