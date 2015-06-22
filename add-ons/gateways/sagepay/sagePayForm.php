<?php

/**
 * 
 * Sage Pay Form SDK includes.php file refactored into class for Events Manager Pro
 * @author Andy Place
 * March 2012
 *
 */
class SagePayForm {

	private $gateway;
	//private $strConnectTo;
	//private $strVirtualDir;
	//private $strYourSiteFQDN;
	//private $strVendorName;
	private $strEncryptionPassword;
	//private $strCurrency;
	//private $strTransactionType;
	//private $strPartnerID;
	//private $bSendEMail;  
	//private $strVendorEMail;    
	private $strEncryptionType;
	//private $bAllowGiftAid;
	//private $bApplyAVSCV2;
	//private $bApply3DSecure;	
	
	function __construct( $gateway ) {
		
		$this->gateway = $gateway;
		
		//$this->strVendorName = get_option('em_'. $this->gateway . "_vendor" );
		$this->strEncryptionPassword = get_option('em_'. $this->gateway . '_encryption_pass'); 
		$this->strEncryptionType = get_option('em_'. $this->gateway . '_encryption_type');
	}
	
	
	//** Wrapper function do encrypt an encode based on strEncryptionType setting **
	public function encryptAndEncode($strIn) {
		
		if ($this->strEncryptionType=="XOR")
		{
			//** XOR encryption with Base64 encoding **
			return base64Encode( simpleXor( $strIn, $this->strEncryptionPassword ) );
		}
		else
		{
			//** AES encryption, CBC blocking with PKCS5 padding then HEX encoding - DEFAULT **

			//** use initialization vector (IV) set from $strEncryptionPassword
			$strIV = $this->strEncryptionPassword;
			 
			//** add PKCS5 padding to the text to be encypted
			$strIn = $this->addPKCS5Padding($strIn);

			//** perform encryption with PHP's MCRYPT module
			$strCrypt = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $this->strEncryptionPassword, $strIn, MCRYPT_MODE_CBC, $strIV);

			//** perform hex encoding and return
			return "@" . bin2hex($strCrypt);
		}
	}

	//** Wrapper function do decode then decrypt based on header of the encrypted field **
	public function decodeAndDecrypt($strIn) {
		if (substr($strIn,0,1)=="@")
		{
			//** HEX decoding then AES decryption, CBC blocking with PKCS5 padding - DEFAULT **

			//** use initialization vector (IV) set from $strEncryptionPassword
			$strIV = $this->strEncryptionPassword;
		 
			//** remove the first char which is @ to flag this is AES encrypted
			$strIn = substr($strIn,1);
			 
			//** HEX decoding
			$strIn = pack('H*', $strIn);
			//** perform decryption with PHP's MCRYPT module

			return $this->removePKCS5Padding(
			mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $this->strEncryptionPassword, $strIn, MCRYPT_MODE_CBC, $strIV));
		}
		else
		{
			//** Base 64 decoding plus XOR decryption **
			return simpleXor(base64Decode($strIn),$this->strEncryptionPassword);
		}
	}
	
	
	
	//** PHP's mcrypt does not have built in PKCS5 Padding, so we use this
	private function addPKCS5Padding($input)
	{
		$blocksize = 16;
		$padding = "";

		// Pad input to an even block size boundary
		$padlength = $blocksize - (strlen($input) % $blocksize);
		for($i = 1; $i <= $padlength; $i++) {
			$padding .= chr($padlength);
		}
		 
		return $input . $padding;
	}
	
	// Need to remove padding bytes from end of decoded string
	private function removePKCS5Padding($decrypted) {
		$padChar = ord( $decrypted[strlen($decrypted) - 1]);	
	    return substr($decrypted, 0, -$padChar); 
	}
	
	
	/* The getToken function.                                                                                         **
	** NOTE: A function of convenience that extracts the value from the "name=value&name2=value2..." reply string **
	** Works even if one of the values is a URL containing the & or = signs.                                      	  */
	public function getToken($thisString) {
	
	  // List the possible tokens
	  $Tokens = array(
	    "Status",
	    "StatusDetail",
	    "VendorTxCode",
	    "VPSTxId",
	    "TxAuthNo",
	    "Amount",
	    "AVSCV2", 
	    "AddressResult", 
	    "PostCodeResult", 
	    "CV2Result", 
	    "GiftAid", 
	    "3DSecureStatus", 
	    "CAVV",
		"AddressStatus",
		"CardType",
		"Last4Digits",
		"PayerStatus",
			"BankAuthCode",
			"DeclineCode",
			"ExpiryDate"
		);
	
	  // Initialise arrays
	  $output = array();
	  $resultArray = array();
	  
	  // Get the next token in the sequence
	  for ($i = count($Tokens)-1; $i >= 0 ; $i--){
	    // Find the position in the string
	    $start = strpos($thisString, $Tokens[$i]);
		// If it's present
	    if ($start !== false){
	      // Record position and token name
	      $resultArray[$i] = new stdClass();
	      $resultArray[$i]->start = $start;
	      $resultArray[$i]->token = $Tokens[$i];
	    }
	  }
	  
	  // Sort in order of position
	  sort($resultArray);
		// Go through the result array, getting the token values
	  for ($i = 0; $i<count($resultArray); $i++){
	    // Get the start point of the value
	    $valueStart = $resultArray[$i]->start + strlen($resultArray[$i]->token) + 1;
		// Get the length of the value
	    if ($i==(count($resultArray)-1)) {
	      $output[$resultArray[$i]->token] = substr($thisString, $valueStart);
	    } else {
	      $valueLength = $resultArray[$i+1]->start - $resultArray[$i]->start - strlen($resultArray[$i]->token) - 2;
		  $output[$resultArray[$i]->token] = substr($thisString, $valueStart, $valueLength);
	    }      
	
	  }
	
	  // Return the ouput array
	  return $output;
	}	
	
}