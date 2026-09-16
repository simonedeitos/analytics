<?php

declare(strict_types=1);

require __DIR__ . '/../includes/importer.php';

$records = [
    ['id' => 1, 'tenant_id' => 7, 'provincia' => 'MI', 'comune' => 'Milàno', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '0010', 'particella' => '0123', 'subalterno' => '0001'],
    ['id' => 2, 'tenant_id' => 7, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '1'],
    ['id' => 3, 'tenant_id' => 7, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => ''],
    ['id' => 4, 'tenant_id' => 7, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '2'],
    ['id' => 5, 'tenant_id' => 7, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => ''],
];

$phpGroups = array_map(static function (array $group): array {
    $ids = array_map(static fn (array $record): int => (int) $record['id'], $group);
    sort($ids);
    return $ids;
}, analyticspro_group_records_by_canonical_unit($records));
usort($phpGroups, static fn (array $left, array $right): int => ($left[0] ?? 0) <=> ($right[0] ?? 0));

$jsFixture = json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$jsCode = <<<JS
const records = {$jsFixture};
function normalizeText(value) {
  var text = String(value === null || value === undefined ? '' : value).trim().toUpperCase();
  if (!text) return '';
  text = text.normalize('NFD').replace(/[\\u0300-\\u036f]/g, '');
  text = text.replace(/[^A-Z0-9\\/]+/g, ' ');
  text = text.replace(/\\s+/g, ' ').trim();
  return text;
}
function normalizeCadastralNumber(value) {
  var text = String(value === null || value === undefined ? '' : value).trim().toUpperCase().replace(/\\s+/g, '');
  if (!text) return '';
  var match = text.match(/^([0-9]+)(.*)$/);
  if (!match) return text;
  var numberPart = String(match[1] || '').replace(/^0+/, '');
  if (!numberPart) numberPart = '0';
  return numberPart + String(match[2] || '');
}
function resolveTenantGroupingId(property) {
  var keys = ['tenant_id', 'tenantId', 'user_id', 'parent_user_id'];
  for (var i = 0; i < keys.length; i++) {
    var value = property[keys[i]];
    if (value !== null && value !== undefined && String(value).trim() !== '') return String(value).trim();
  }
  return '';
}
function normalizeCadastralIdentity(record) {
  return {
    tenant_id: resolveTenantGroupingId(record),
    provincia: normalizeText(record.provincia || ''),
    comune: normalizeText(record.comune || ''),
    cod_catastale: normalizeText(record.cod_catastale || ''),
    sezione: normalizeText(record.sezione || ''),
    foglio: normalizeCadastralNumber(record.foglio || ''),
    particella: normalizeCadastralNumber(record.particella || ''),
    subalterno: normalizeCadastralNumber(record.subalterno || '')
  };
}
function cadastralBaseBucketKey(identity) {
  return [identity.tenant_id, identity.provincia, identity.sezione, identity.foglio, identity.particella].join('|');
}
function makeCadastralCluster(entries) {
  var cluster = { entries: [], cod_catastale: '', comuni: {} };
  appendEntriesToCadastralCluster(cluster, entries || []);
  return cluster;
}
function appendEntriesToCadastralCluster(cluster, entries) {
  (entries || []).forEach(function (entry) {
    cluster.entries.push(entry);
    if (!cluster.cod_catastale && entry.identity.cod_catastale) cluster.cod_catastale = entry.identity.cod_catastale;
    if (entry.identity.comune) cluster.comuni[entry.identity.comune] = true;
  });
}
function clusterMatchesMissingCodeGroup(cluster, comune) {
  return !!(comune && cluster.comuni && cluster.comuni[comune]);
}
function clusterMatchesMissingSubalterno(cluster, identity) {
  var clusterCode = String(cluster.cod_catastale || '');
  var identityCode = String(identity.cod_catastale || '');
  if (clusterCode && identityCode) return clusterCode === identityCode;
  return !!(identity.comune && cluster.comuni && cluster.comuni[identity.comune]);
}
function buildNonEmptySubalternoClusters(entries) {
  var coded = {};
  var codedOrder = [];
  var missingCode = {};
  var missingOrder = [];
  (entries || []).forEach(function (entry) {
    if (entry.identity.cod_catastale) {
      var codedKey = 'COD:' + entry.identity.cod_catastale;
      if (!coded[codedKey]) { coded[codedKey] = makeCadastralCluster([]); codedOrder.push(codedKey); }
      appendEntriesToCadastralCluster(coded[codedKey], [entry]);
      return;
    }
    var missingKey = entry.identity.comune ? ('COM:' + entry.identity.comune) : ('REC:' + entry.index);
    if (!missingCode[missingKey]) { missingCode[missingKey] = makeCadastralCluster([]); missingOrder.push(missingKey); }
    appendEntriesToCadastralCluster(missingCode[missingKey], [entry]);
  });
  var clusters = codedOrder.map(function (key) { return coded[key]; });
  missingOrder.forEach(function (key) {
    var cluster = missingCode[key];
    var comuni = Object.keys(cluster.comuni || {});
    var candidates = [];
    if (comuni.length) {
      clusters.forEach(function (candidate, index) {
        if (clusterMatchesMissingCodeGroup(candidate, comuni[0])) candidates.push(index);
      });
    }
    if (candidates.length === 1) appendEntriesToCadastralCluster(clusters[candidates[0]], cluster.entries);
    else clusters.push(cluster);
  });
  return clusters;
}
function groupCanonicalRecords(records) {
  var buckets = {};
  var order = [];
  (records || []).forEach(function (record, index) {
    var identity = normalizeCadastralIdentity(record);
    var key = cadastralBaseBucketKey(identity);
    if (!buckets[key]) { buckets[key] = []; order.push(key); }
    buckets[key].push({ record: record, identity: identity, index: index });
  });
  var groups = [];
  order.forEach(function (key) {
    var entries = buckets[key] || [];
    var bySub = {};
    var subOrder = [];
    var emptySub = [];
    entries.forEach(function (entry) {
      if (!entry.identity.subalterno) { emptySub.push(entry); return; }
      if (!bySub[entry.identity.subalterno]) { bySub[entry.identity.subalterno] = []; subOrder.push(entry.identity.subalterno); }
      bySub[entry.identity.subalterno].push(entry);
    });
    var clusters = [];
    subOrder.forEach(function (sub) { buildNonEmptySubalternoClusters(bySub[sub]).forEach(function (cluster) { clusters.push(cluster); }); });
    emptySub.forEach(function (entry) {
      var candidates = [];
      clusters.forEach(function (cluster, index) {
        if (clusterMatchesMissingSubalterno(cluster, entry.identity)) candidates.push(index);
      });
      if (candidates.length === 1) appendEntriesToCadastralCluster(clusters[candidates[0]], [entry]);
      else clusters.push(makeCadastralCluster([entry]));
    });
    clusters.forEach(function (cluster) {
      groups.push(cluster.entries.map(function (entry) { return entry.record.id; }).sort(function (a, b) { return a - b; }));
    });
  });
  groups.sort(function (a, b) { return (a[0] || 0) - (b[0] || 0); });
  console.log(JSON.stringify(groups));
}
groupCanonicalRecords(records);
JS;

$tmpFile = tempnam(sys_get_temp_dir(), 'ap_js_group_');
file_put_contents($tmpFile, $jsCode);
$jsOutput = shell_exec('node ' . escapeshellarg($tmpFile));
@unlink($tmpFile);

$pass = true;
$errors = [];
if (!is_string($jsOutput) || trim($jsOutput) === '') {
    $pass = false;
    $errors[] = 'Node non ha restituito alcun output per il test di simmetria JS/PHP.';
} else {
    $jsGroups = json_decode(trim($jsOutput), true);
    if ($jsGroups !== $phpGroups) {
        $pass = false;
        $errors[] = 'I gruppi JS e PHP non coincidono: PHP=' . json_encode($phpGroups) . ' JS=' . json_encode($jsGroups);
    }
}

if ($pass) {
    echo "PASS: simmetria raggruppamento PHP/JS OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
