<?php

class Desa_model extends CI_Model{

  var $table = 'desa';
  var $column_order = array(null, 'nama_desa','nama_kecamatan','nama_kabupaten','nama_provinsi','web','offline','online','tgl_rekam'); //set column field database for datatable orderable
  var $column_order_kabupaten = array(null, 'nama_kabupaten','nama_provinsi','offline','online'); //set column field database for datatable orderable
  var $column_order_versi = array(null, 'opensid_version','offline','online'); //set column field database for datatable orderable
  var $column_search = array('nama_desa','nama_kecamatan','nama_kabupaten','nama_provinsi'); //set column field database for datatable searchable
  var $order = array('id' => 'asc'); // default order

  public function __construct(){
    parent::__construct();
    $this->load->database();
    $this->load->library('user_agent');
    $this->load->model('provinsi_model');
  }

  public function insert(&$data){
    $url = $data['url'];
    $data['url'] = preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST));
    $version = $data['version'];
    $external_ip = $data['external_ip'];
    unset($data['version']);
    unset($data['external_ip']);

    // Masalah dengan auto_increment meloncat. Paksa supaya berurutan.
    // https://ubuntuforums.org/showthread.php?t=2086550
    $sql = "ALTER TABLE desa AUTO_INCREMENT = 1";
    $this->db->query($sql);
    $data['nama_provinsi'] = $this->_normalkan_spasi($data['nama_provinsi']);
    $data['nama_provinsi'] = $this->provinsi_model->nama_baku($data['nama_provinsi']);
    if (!$this->provinsi_model->cek_baku($data['nama_provinsi']))
      $data['jenis'] = 2; // jenis = 2 jika nama provinsi tidak baku
    $data['nama_kabupaten'] = $this->_normalkan_spasi($data['nama_kabupaten']);
    $data['nama_kecamatan'] = $this->_normalkan_spasi($data['nama_kecamatan']);
    $data['nama_desa'] = $this->_normalkan_spasi($data['nama_desa']);
    $data['id'] = $this->_desa_baru($data);
    if (empty($data['id'])){
      $data['is_local'] = (is_local($data['url']) or is_local($data['ip_address'])) ? '1' : '0';
      $data['tgl_rekam'] = $data['tgl_ubah'];
      $out = $this->db->insert('desa', $data);
      $data['id'] = $this->db->insert_id();
      $this->email_github($data);
      $hasil = "<br>Desa baru: ".$data['id'];
    } else {
      $out = $this->db->where('id',$data['id'])->update('desa',$data);
      $hasil = "<br>Desa lama: ".$data['id'];
    }
    $data['version'] = $version;
    $data['external_ip'] = $external_ip;
   return $hasil." ".$out;
  }

  private function _normalkan_spasi($str){
    return trim(preg_replace('/\s+/', ' ', $str));
  }

  private function _desa_baru($data){
    /*
      Dibuat entri di tabel desa untuk setiap kombinasi:
        1. Nama desa, nama kec, nama kab, nama prov, is_local = FALSE (untuk online)
        2. Nama desa, nama kec, nama kab, nama prov, is_local = TRUE (untuk offline)
      Dengan cara ini, setiap entri berisi data akses terakhir untuk masing2 offline
      dan online.

      Untuk offline maupun online, akses terakhir bisa saja dari:
      a. Server desa sebenarnya
      b. Server contoh/pelatihan/demo yang menggunakan nama desa tersebut
      c. Server backup dari desa sebenarnya

      Cara ini menjamin maksimum ada satu entri offline dan online untuk setiap desa.

      Entri desa 'salah', yaitu desa yang sebenarnya tidak ada atau tampak sebagai duplikat,
      bisa terjadi, karena:
      a. penulisan nama desa/kecamatan/kabupaten/provinsi sebagai percobaan/contoh,
      b. penulisan nama desa/kecamatan/kabupaten/provinsi salah dan diubah

      Cara ini dianggap memadai, karena:
      a. Untuk offline, yang tidak bisa diakses melalui internet,
         sumber akses terakhir tidak penting
      b. Untuk online, diperkirakan akan jarang ada yang menggunakan nama desa lain,
         kecuali situs demo yang diabaikan (tidak direkam)
      c. Desa 'salah' atau 'duplikat' akan terfilter dari laporan jika tidak diakses lagi
         dalam masa 2 bulan.

      Karena tabel akses berisi rincian setiap akses yang direkam, laporan rincian akses
      bisa ditampilkan apabila perlu.

      TODO: Jangka panjang, akan digunakan daftar desa baku, sehingga tidak akan ada entri untuk desa yang sebenarnya tidak ada atau tertulis salah.
    */
    $cek_desa = array(
      "nama_desa" => strtolower($data['nama_desa']),
      "nama_kecamatan" => strtolower($data['nama_kecamatan']),
      "nama_kabupaten" => strtolower($data['nama_kabupaten']),
      "nama_provinsi" => strtolower($data['nama_provinsi'])
      );
    if (is_local($data['url']) or is_local($data['ip_address'])) {
      $cek_desa = array_merge($cek_desa, array("is_local" => '1'));
    } else
      $cek_desa = array_merge($cek_desa, array("is_local" => '0'));
    $id = $this->db->select('id')->where($cek_desa)->get('desa')->row()->id;
    return $id;
  }

  private function filter_sql(){
    if(isset($_SESSION['filter'])){
      $filter = $_SESSION['filter'];
      if ($filter == 1)
        $filter_sql = " AND NOT url_referrer LIKE '%localhost%' AND NOT url_referrer LIKE '%192.168%' AND NOT url_referrer LIKE '%127.0.0.1%' AND NOT url_referrer LIKE '%/10.%'";
      else
        $filter_sql = " AND (url_referrer LIKE '%localhost%' OR url_referrer LIKE '%192.168%' OR url_referrer LIKE '%127.0.0.1%' OR url_referrer LIKE '%/10.%')";
    return $filter_sql;
    }
  }

  function paging($offset=0,$main_sql){

    $sql      = "SELECT COUNT(id) AS jml ".$main_sql;
    $query    = $this->db->query($sql);
    $row      = $query->row_array();
    $jml_data = $row['jml'];

    $this->load->library('pagination');
    $cfg["base_url"] = base_url() . "index.php/laporan/index";
    $cfg['page']     = $offset;
    $cfg['per_page'] = 20;
    // $cfg['per_page'] = $_SESSION['per_page'];
    $cfg['total_rows'] = $jml_data;
    $this->pagination->initialize($cfg);
    return $this->pagination;
  }

  public function list_desa($offset=0){
    $main_sql = $this->_get_main_query();
    $main_sql .= $this->filter_sql();
    $this->paging($offset, $main_sql);
    $paging_sql = ' LIMIT ' .$offset. ',' .$this->pagination->per_page;
    $sql = "SELECT * ".$main_sql;
    $sql .= $paging_sql;

    $query = $this->db->query($sql);
    $data['list_desa'] = $query->result_array();
    $data['links'] = $this->pagination->create_links();
    return $data;
  }

  /*
    Jangan rekam, jika:
    - ada kolom nama wilayah kosong
    - ada kolom wilayah yang masih merupakan contoh (berisi karakter non-alpha)
  */
  public function abaikan($data){
    $abaikan = false;
    $desa = trim($data['nama_desa']);
    $kec = trim($data['nama_kecamatan']);
    $kab = trim($data['nama_kabupaten']);
    $prov = trim($data['nama_provinsi']);
    if ( empty($desa) OR empty($kec) OR empty($kab) OR empty($prov) ) {
      $abaikan = true;
    } elseif (preg_match('/[^a-zA-Z\s:]/', $desa) OR
        preg_match('/[^a-zA-Z\s:]/', $kec) OR
        preg_match('/[^a-zA-Z\s:]/', $kab) OR
        preg_match('/[^a-zA-Z\s:]/', $prov)
       ) {
      $abaikan = true;
    }
    // Abaikan situs demo
    if (preg_match('/sid.bangundesa.info|demosid.opensid.info|sistemdesa.sunshinecommunity.id/', $data['url']))
      $abaikan = true;
    return $abaikan;
  }

// ===============================

  private function _get_main_query()
  {

    $main_sql = "FROM
      (SELECT nama_desa, nama_kecamatan, nama_kabupaten, nama_provinsi,
        max(tgl_ubah) as tgl_ubah,
        max(web) as web,
        min(tgl_rekam) as tgl_rekam,
        max(offline) as offline,
        max(online) as online,
        max(jenis) as jenis
      FROM
      (SELECT nama_desa, nama_kecamatan, nama_kabupaten, nama_provinsi, DATE_FORMAT(tgl_rekam, '%Y-%m-%d') as tgl_rekam, is_local, tgl_ubah, jenis,
        CASE WHEN is_local = 0 THEN url ELSE '' END as web,
        (SELECT opensid_version
          FROM akses WHERE d.id = desa_id and d.is_local = 0 ORDER BY tgl DESC LIMIT 1) as online,
        (SELECT opensid_version
          FROM akses WHERE d.id = desa_id and d.is_local = 1 ORDER BY tgl DESC LIMIT 1) as offline
      FROM desa d) z
      GROUP By nama_desa, nama_kecamatan, nama_kabupaten, nama_provinsi) w
      WHERE 1
    ";
    $main_sql .= $this->_akses_query();
    return $main_sql;
  }

  private function _get_filtered_query()
  {
    $filtered_query = $this->_get_main_query();
    if($this->input->post('is_local') !== null) {
      switch ($this->input->post('is_local')) {
        case '0':
          $filtered_query .= " AND online <> '' ";
          break;
        case '1':
          $filtered_query .= " AND offline <> '' ";
          break;
      }
    }
    $kab = $this->input->post('kab');
    if(!empty($kab)) {
        $filtered_query .= " AND nama_kabupaten = '{$kab}'";
    }
    $sSearch = $_POST['search']['value'];
    $filtered_query .= " AND (nama_desa LIKE '%".$sSearch."%' or nama_kecamatan LIKE '%".$sSearch."%' or nama_kabupaten LIKE '%".$sSearch."%' or nama_provinsi LIKE '%".$sSearch."%') ";
    return $filtered_query;
  }

  // Hanya laporkan desa yang situsnya diakses dalam 3 bulan terakhir
  private function _akses_query()
  {
    $sql = " AND TIMESTAMPDIFF(MONTH, tgl_ubah, NOW()) <= 2 ";
    return $sql;
  }

  function get_datatables()
  {
    $qry = "SELECT * ".$this->_get_filtered_query();
    if(isset($_POST['order'])) // here order processing
    {
      $sort_by = $this->column_order[$_POST['order']['0']['column']];
      $sort_type = $_POST['order']['0']['dir'];
      $qry .= " ORDER BY ".$sort_by." ".$sort_type;
    } else {
      $qry .= " ORDER BY nama_provinsi, nama_kabupaten, nama_kecamatan, nama_desa";
    }
    if($_POST['length'] != -1)
     $qry .= " LIMIT ".$_POST['start'].", ".$_POST['length'];
    $query = $this->db->query($qry);
    return $query->result_array();
  }

  function count_filtered()
  {
    $sql = "SELECT COUNT(*) AS jml ".$this->_get_filtered_query();
    $jml = $this->db->query($sql)->row()->jml;
    return $jml;
  }

  public function count_all()
  {
    $sql = "SELECT COUNT(*) AS jml ".$this->_get_main_query();
    $jml = $this->db->query($sql)->row()->jml;
    return $jml;
  }

  private function _filtered_kabupaten_query(){
    $filtered_query = $this->_main_kabupaten_query();
    if($this->input->post('is_local') !== null) {
      switch ($this->input->post('is_local')) {
        case '0':
          $filtered_query .= " AND online > 0 ";
          break;
        case '1':
          $filtered_query .= " AND offline > 0 ";
          break;
      }
    }
    $sSearch = $_POST['search']['value'];
    $filtered_query .= " AND (nama_kabupaten LIKE '%".$sSearch."%' OR nama_provinsi LIKE '%".$sSearch."%')";
    return $filtered_query;
  }

  function count_filtered_kabupaten()
  {
    $sql = "SELECT COUNT(*) AS jml ".$this->_filtered_kabupaten_query();
    $jml = $this->db->query($sql)->row()->jml;
    return $jml;
  }

  public function count_all_kabupaten()
  {
    $jumlah = $this->db->select('count(DISTINCT nama_kabupaten, nama_provinsi) as jumlah')->from('desa')->get()->row()->jumlah;
    return $jumlah;
  }

  function _main_kabupaten_query(){
    $query = " FROM
      (SELECT DISTINCT nama_provinsi, nama_kabupaten,
        (SELECT count(*)
        FROM desa x where x.nama_provinsi = d.nama_provinsi and x.nama_kabupaten = d.nama_kabupaten and x.is_local = 1) offline,
        (SELECT count(*)
        FROM desa x where x.nama_provinsi = d.nama_provinsi and x.nama_kabupaten = d.nama_kabupaten and x.is_local = 0) online
        from desa d
      ) z
      WHERE 1
    ";
    return $query;
  }

  function profil_kabupaten(){
    $qry = "SELECT * ".$this->_filtered_kabupaten_query();

    if(isset($_POST['order'])) // here order processing
    {
      $sort_by = $this->column_order_kabupaten[$_POST['order']['0']['column']];
      $sort_type = $_POST['order']['0']['dir'];
      $qry .= " ORDER BY ".$sort_by." ".$sort_type;
    } else {
      $qry .= " ORDER BY nama_provinsi, nama_kabupaten";
    }
    if($_POST['length'] != -1)
      $qry .= " LIMIT ".$_POST['start'].", ".$_POST['length'];

    $data = $this->db->query($qry)->result_array();
    return $data;
  }

  private function _main_versi_query() {
    $query = " FROM
      (select opensid_version,
        sum(case when is_local = 1 then 1 else 0 end) offline,
        sum(case when is_local = 0 then 1 else 0 end) online
      from
        (SELECT is_local,
          (SELECT opensid_version FROM akses a where d.id = desa_id order by tgl desc limit 1) as opensid_version
        FROM desa d) z
      group by opensid_version) w
      WHERE 1
    ";
    return $query;
  }

  private function _filtered_versi_query(){
    $filtered_query = $this->_main_versi_query();
    if($this->input->post('is_local') !== null) {
      switch ($this->input->post('is_local')) {
        case '0':
          $filtered_query .= " AND online > 0 ";
          break;
        case '1':
          $filtered_query .= " AND offline > 0 ";
          break;
      }
    }
    $sSearch = $_POST['search']['value'];
    $filtered_query .= " AND opensid_version LIKE '%".$sSearch."%'";
    return $filtered_query;
  }

  function count_filtered_versi()
  {
    $sql = "SELECT COUNT(*) AS jml ".$this->_filtered_versi_query();
    $jml = $this->db->query($sql)->row()->jml;
    return $jml;
  }

  public function count_all_versi()
  {
    $sql = "SELECT COUNT(*) AS jml ".$this->_main_versi_query();
    $query    = $this->db->query($sql);
    $row      = $query->row_array();
    return $row['jml'];
  }

  function profil_versi(){
    $qry = "SELECT * ".$this->_filtered_versi_query();

    if(isset($_POST['order'])) // here order processing
    {
      $sort_by = $this->column_order_versi[$_POST['order']['0']['column']];
      $sort_type = $_POST['order']['0']['dir'];
      $qry .= " ORDER BY ".$sort_by." ".$sort_type;
    } else
      $qry .= "ORDER BY opensid_version DESC";
    if($_POST['length'] != -1)
     $qry .= " LIMIT ".$_POST['start'].", ".$_POST['length'];
    $query = $this->db->query($qry);
    return $query->result_array();
  }

  private function email($subject, $message, $to="eddie.ridwan@gmail.com"){
    $this->load->library('email'); // Note: no $config param needed
    $this->email->from('opensid.server@gmail.com', 'OpenSID Tracker');
    $this->email->to($to);
    $this->email->subject($subject);
    $this->email->message($message);
    if ($this->email->send())
      echo "<br>Email desa baru: ".$message;
    else show_error($this->email->print_debugger());
  }

  private function email_github($data){
    $message =
      "Desa: ".$data['nama_desa']."\r\n".
      "Kecamatan: ".$data['nama_kecamatan']."\r\n".
      "Kabupaten: ".$data['nama_kabupaten']."\r\n".
      "Provinsi: ".$data['nama_provinsi']."\r\n".
      "Website: "."http://".$data['url']."\r\n";
    $this->load->library('email'); // Note: no $config param needed
    $this->email->from('opensid.server@gmail.com', 'Desa OpenSID');
    $this->email->to("reply+0003cedb28a15af7509fdc8d2eea2ad81330dadac78af6e492cf0000000115b3c7f892a169ce0f03d3ca@reply.github.com");
    $this->email->subject("Desa Pengguna OpenSID");
    $this->email->message($message);
    if ($this->email->send())
      echo "<br>Email desa baru ke Github: ".$message;
    else show_error($this->email->print_debugger());
  }
}
?>