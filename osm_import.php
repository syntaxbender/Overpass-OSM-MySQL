<?php
/*
*
*	Author : SyntaxBender
*	Description : importing administrative boundaries of openstreetmap via overpass to mysql
*
*/
class Utils{
	public function curl($url,$cookie,$post,$customheader,$header=1){
		$ch = @curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, $header);
		if(empty($cookie) === false) curl_setopt($ch, CURLOPT_COOKIE, $cookie);
		if(empty($post) === false) {
			curl_setopt($ch,CURLOPT_POST, 1);
			curl_setopt($ch,CURLOPT_POSTFIELDS,$post);
		}
		if(empty($customheader) === false) curl_setopt($ch, CURLOPT_HTTPHEADER,$customheader);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.72 Safari/537.36');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
		$page = curl_exec($ch);
		curl_close($ch); 
		return $page;
	}
}
class MySQLUtils{
	public $db;
	public function __construct($dbname,$user,$pass,$host = "localhost"){
		try {
			$this->db = new PDO("mysql:host=".$host.";dbname=".$dbname, $user, $pass);
			$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch ( PDOException $e ){
			print $e->getMessage();
			exit();
		}
	}
	public function createGeometryQuery($value){
		$query = "";
		if($value["geometry"]["type"] == "Polygon"){
			$query = "Polygon((";
			if($value["geometry"]["coordinates"][0]>0){
				foreach ($value["geometry"]["coordinates"][0] as $key_point => $point){
					$query .= round($point[1],3)." ".round($point[0],3);
					if($key_point != count($value["geometry"]["coordinates"][0])-1) $query .= ",";
				}
			}else{
				throw new Exception($value["id"]." - ".$value["properties"]["name"]." - geometry verisi hatalı - ".$value["geometry"]["type"]." - ".json_encode($value["geometry"]["coordinates"])."\n");
			}
			$query .= "))";
		
		}else if($value["geometry"]["type"] == "MultiPolygon"){
			$query = "MultiPolygon(";
			if($value["geometry"]["coordinates"]>0){
				foreach ($value["geometry"]["coordinates"] as $key_poly => $polygon){
					$query .= "((";
					foreach ($polygon[0] as $key_point => $point) {
						$query .= round($point[1],3)." ".round($point[0],3);
						if($key_point != count($polygon[0])-1) $query .= ",";
					}
					$query .= "))";
					if($key_poly != count($value["geometry"]["coordinates"])-1) $query .= ",";
				}
			}else{
				throw new Exception($value["id"]." - ".$value["properties"]["name"]." - geometry verisi hatalı - ".$value["geometry"]["type"]." - ".json_encode($value["geometry"]["coordinates"])."\n");
			}
			$query .= ")";
		}else throw new Exception($value["id"]." - ".$value["properties"]["name"]." - geçersiz veri türü - ".$value["geometry"]["type"]." - ".json_encode($value["geometry"]["coordinates"])."\n");
		return $query;
	}
}
class OSMImport{
	public $insertedElements;
	public $db;
	public $data;
	public $relKeys;
	public $count;
	public function __construct() {
		$this->insertedElements = [];
		$this->db = new MySQLUtils("spatial3", "root","12345678","localhost");
		$this->data = json_decode(file_get_contents("osm_turkey.geojson"),1)["features"];
		$this->relKeys = array_flip(array_column($this->data, 'id'));
		foreach ($this->data as $key => $polygon){
			if(($polygon["geometry"]["type"] == "Polygon" || $polygon["geometry"]["type"] == "MultiPolygon") && isset($polygon["properties"]["name"]) && isset($polygon["properties"]["admin_level"])){
				$this->insert(str_replace("relation/","",$polygon["id"]));
			}
		}
	}
	//public function insertNeighborhood(){}
	//public function insertCountry(){}
	public function insertProvince($polygon,$countryID,$relKey){
		try{
			$query_il = $this->db->createGeometryQuery($polygon);
			$sqlquery = $this->db->db->prepare("INSERT INTO cities (name,country_id,geometry,osmrel) VALUES (?,?,ST_GeomFromText(?,4326),?);");
			$sqlquery->execute([$polygon["properties"]["name"],$countryID,$query_il,$relKey]);
			$ilID = $this->db->db->lastInsertId();
			$this->insertedElements[$relKey]=$ilID;
		}catch(PDOException $e){
			echo $polygon["properties"]["name"]." - ".$polygon["properties"]["name"]." - ";
			echo $e->getMessage()."\n";
			echo $query_il."\n\n\n";
			return false;
		}catch(Exception $e){
			echo $polygon["properties"]["name"]." - ".$polygon["properties"]["name"]." - ";
			echo $e->getMessage()."\n";
			echo $query_il."\n\n\n";
			return false;
		}
		return $ilID;
	}
	public function insertDistrict($polygon,$countryID,$provinceID,$relKey){
		try{
			$queryDist = $this->db->createGeometryQuery($polygon);
			$sqlquery = $this->db->db->prepare("INSERT INTO counties (name,country_id,city_id,geometry,osmrel) VALUES (?,?,?,ST_GeomFromText(?,4326),?);");
			$sqlquery->execute([$polygon["properties"]["name"],$countryID,$provinceID,$queryDist,$relKey]);
			$ilceID = $this->db->db->lastInsertId();
			$this->insertedElements[$relKey]=$ilceID;
		}catch(PDOException $e){
			echo $polygon["properties"]["text"]." - ".$polygon["properties"]["text"]." - ";
			echo $e->getMessage()."\n";
			echo $query_ilce."\n\n\n";
			return false;
		}catch(Exception $e){
			echo $polygon["properties"]["text"]." - ".$polygon["properties"]["text"]." - ";
			echo $e->getMessage()."\n";
			echo $query_ilce."\n\n\n";
			return false;
		}
		return $ilceID;
	}
	public function getInsertedParents($rel){
		$this->count=0;
		while(isset($rel) && empty($rel) === false){
			$dataid = $this->relKeys["relation/".$rel];
			$callback = [];
			@$parent = $this->data[$dataid]["properties"]["@relations"][0]["rel"];
			if ($this->data[$dataid]["properties"]["admin_level"] == 2) {
				$callback["country"] = $this->insertedElements[$rel];
			}else if ($this->data[$dataid]["properties"]["admin_level"] == 4) {
				$callback["province"] = $this->insertedElements[$rel];
			}else if ($this->data[$dataid]["properties"]["admin_level"] == 6) {
				$callback["district"] = $this->insertedElements[$rel];
			}else if ($this->data[$dataid]["properties"]["admin_level"] == 8) {
				$callback["neighborhood"] = $this->insertedElements[$rel];
			}
			$rel = $parent;
			$this->count++;
		};
		return $callback;
	}
	public function insert($rel){
		$temp = [];
		
		while(isset($rel) && empty($rel) === false && isset($this->insertedElements[$rel]) === false){
			$dataid = $this->relKeys["relation/".$rel];
			$temp[] = $dataid;
			@$parent = $this->data[$dataid]["properties"]["@relations"][0]["rel"];
			$rel = $parent;
		};

		if(isset($rel) && empty($rel)===false) $inserted = $this->getInsertedParents($rel);
		if(isset($inserted["country"])) $countryID = $inserted["country"];
		else $countryID = 1;
		if(isset($inserted["province"])) $provinceID = $inserted["province"];
		else $provinceID = 1;
		if(isset($inserted["district"])) $districtID = $inserted["district"];
		else $districtID = 1;
		if(isset($inserted["neighborhood"])) $neighborhoodID = $inserted["neighborhood"];
		else $neighborhoodID = 1;

		/* edit this scope for add new admin_level */
		for($i=count($temp)-1; $i>-1; $i--){
			/*if ($data[$temp[$i]]["properties"]["admin_level"] == 2){
				$countryID = 1;
			}else*/
			if($this->data[$temp[$i]]["properties"]["admin_level"] == 4){
				$provinceID = $this->insertProvince($this->data[$temp[$i]],$countryID,str_replace("relation/","",$this->data[$temp[$i]]["id"]));
			}else if($this->data[$temp[$i]]["properties"]["admin_level"] == 6){
				$districtID = $this->insertDistrict($this->data[$temp[$i]],$countryID,$provinceID,str_replace("relation/","",$this->data[$temp[$i]]["id"]));
			}
			/*else if ($data[$temp[$i]]["properties"]["admin_level"] == 8){
				//$neighborhoodID = insertNeighborhood($polygon,$provinceID,$relKey);
			}*/
		}
	}
}
new OSMImport();