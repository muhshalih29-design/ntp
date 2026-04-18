create table if not exists ntp_subsektor (
    prov varchar(10) not null,
    tahun integer not null,
    bulan varchar(20) not null,
    subsektor varchar(120) not null,
    rincian varchar(255) not null,
    nilai numeric(12,2) not null,
    upload_batch_id varchar(32),
    uploaded_at timestamp without time zone default current_timestamp,
    primary key (prov, tahun, bulan, subsektor, rincian)
);

create table if not exists andil_ntp (
    subsektor varchar(30) not null,
    prov varchar(10) not null,
    jnsbrg varchar(50) not null,
    komoditi varchar(255) not null,
    kel varchar(30) not null,
    rincian varchar(255) not null,
    kode_bulan varchar(10) not null,
    tahun integer not null,
    andil numeric(12,4) not null,
    upload_batch_id varchar(32),
    uploaded_at timestamp without time zone default current_timestamp,
    primary key (subsektor, prov, jnsbrg, komoditi, kel, rincian, kode_bulan, tahun)
);

create table if not exists upload_log (
    id bigserial primary key,
    table_name varchar(50) not null,
    batch_id varchar(32) not null,
    inserted integer not null default 0,
    updated integer not null default 0,
    duplicates integer not null default 0,
    skipped integer not null default 0,
    created_at timestamp without time zone not null default current_timestamp
);

create table if not exists brs_alasan_subsektor (
    period_tahun integer not null,
    period_bulan smallint not null,
    subsektor_key varchar(10) not null,
    alasan varchar(200) not null,
    updated_at timestamp without time zone not null default current_timestamp,
    primary key (period_tahun, period_bulan, subsektor_key)
);

create index if not exists idx_ntp_subsektor_period
    on ntp_subsektor (prov, tahun, bulan);

create index if not exists idx_ntp_subsektor_rincian
    on ntp_subsektor (prov, rincian, subsektor);

create index if not exists idx_andil_ntp_period
    on andil_ntp (prov, tahun, kode_bulan);

create index if not exists idx_upload_log_table_created
    on upload_log (table_name, created_at desc);
