// Source-reviewed acceptance data, never used by production mapping or prompts.
import {readFileSync,writeFileSync} from 'node:fs';
const cpt=JSON.parse(readFileSync(new URL('./reference-cpt.json',import.meta.url),'utf8'));
const fca=JSON.parse(readFileSync(new URL('./reference-fca.json',import.meta.url),'utf8'));
const found=(values,sources,note='')=>({expected:'found',values,sources,note});
const manual=note=>({expected:'manual',values:[],sources:[],note});
const owned=expected=>({expected,values:[],sources:[],note:'Backend-owned; not a missing documentary fact.'});
const rows=[
 ['1',owned('profile'),owned('profile')],
 ['2',found(['ANHUI','RUIBAI'],[3,7]),found(['Fuller','Deutschland'],[1,6],'Shipper/exporter differs from the invoice billing seller; preserve the actual address.')],
 ['3',owned('not_applicable'),owned('not_applicable')],['4',owned('not_applicable'),owned('not_applicable')],
 ['5',owned('calculated'),owned('calculated')],
 ['6',found(['34'],[7],'34 outer pallets contain 136 drums; do not add the two counts.'),found(['2'],[1,6])],
 ['7',owned('not_applicable'),owned('not_applicable')],
 ['8',found(['HIMCENTR'],[3,7]),found(['KT&G','71'],[1,3,6],'Ship To is accounting quarter 060, Kokozek 71; not Bill To 062/50A.')],
 ['9',found(['HIMCENTR'],[2,5]),found(['KT&G','50A'],[3,4],'Bill To differs from the delivery address.')],
 ['10',owned('not_applicable'),owned('not_applicable')],
 ['11',found(['CN'],[2,3]),found(['DE'],[3,4])],
 ['12',owned('calculated'),owned('calculated')],['13',owned('not_applicable'),owned('not_applicable')],
 ['14',found(['Химцентр'],[1,2],'Importer/client, not automatically the customs service company.'),manual('Confirm the declarant role of the importer; no power of attorney for the new declaration is supplied.')],
 ['15',found(['CN'],[3,7]),found(['DE'],[1,3,6])],['15a',found(['CN'],[3,7]),found(['DE'],[1,3,6])],
 ['16',found(['CN'],[9]),found(['DE'],[3])],
 ['17',owned('profile'),owned('profile')],['17a',owned('profile'),owned('profile')],
 ['18',found(['944'],[7],'CMR vehicle plate; do not use the CMR/invoice number.'),manual('AWB flight identifiers do not establish aircraft registration; retain the factual flight separately.')],
 ['19',manual('No reliable explicit container declaration in the primary set.'),manual('Pallets do not prove absence of a container.')],
 ['20',found(['CPT','Almaty'],[3,6]),found(['FCA','Nienburg'],[3,6])],
 ['21',found(['944'],[7]),manual('Flight TK6592 is present; aircraft registration/country is not established.')],
 ['22',found(['USD'],[3,6]),found(['EUR'],[0,3])],['23',owned('reference'),owned('reference')],
 ['24',manual('Classify the commercial transaction from contract terms and the official list; no literal code in primary documents.'),manual('Select the transaction code from the contract and classifier; do not copy an old DT.')],
 ['25',found(['30'],[7]),found(['40'],[1],'International air leg, not Truck from the factory pre-carriage.')],
 ['26',found(['30'],[7]),manual('Domestic Kazakhstan leg must not be inferred from invoice Truck at Nienburg.')],
 ['27',owned('not_applicable'),owned('not_applicable')],['28',owned('not_applicable'),owned('not_applicable')],
 ['29',found(['57503'],[7,8],'Entry stamp, distinct from destination customs post 39855411.'),manual('Airport stamp/code needs confirmation; flight destination alone is not a customs office code.')],
 ['30',found(['MKS','29'],[7],'CMR box 13: storage company/address, not final commercial consignee address.'),manual('Ship To address is not evidence of the customs storage location.')],
 ['31',found(['ETHYL','ACETATE'],[3,6,7]),found(['2934'],[0,3,6])],['32',owned('calculated'),owned('calculated')],
 ['33',{...found(['2915310000'],[3]),expected:'reference',note:'AI selects an existing catalogue entry from ETHYL ACETATE; documentary HS is 29153100.'},found(['3506910000'],[0],'XLSX G4 is the EAEU terminal code; foreign eight-digit HS 35069190 is not padded.')],
 ['34',found(['CN'],[9]),found(['DE'],[3])],
 ['35',{...found(['26792','27860'],[7,9]),expected:'conflict'},found(['1248'],[1,3,6])],
 ['36',owned('profile'),owned('profile')],
 ['37',manual('Procedure 40 belongs to the profile; previous procedure/features need documentary or user confirmation.'),manual('Procedure 40 is known; previous procedure/features are not established by silence.')],
 ['38',found(['24480'],[6,9]),found(['1200'],[0,3,6])],
 ['39',manual('Absence of a quota claim is not an explicit documentary answer.'),manual('No explicit quota applicability answer in primary documents.')],
 ['40',owned('not_applicable'),owned('not_applicable')],['41',owned('reference'),owned('reference')],
 ['42',found(['37454.40'],[3,6]),found(['2844'],[0,3])],['43',owned('profile'),owned('profile')],
 ['44',found(['777','AHRBXS'],[2,3,8],'Contract/invoice records and other supporting documents; not product rows.'),found(['2601011274','235'],[1,3])],
 ['45',owned('calculated'),owned('calculated')],['46',owned('calculated'),owned('calculated')],['47',owned('calculated'),owned('calculated')],
 ['B',manual('Commercial payment order 393 is not proof of customs tax payment.'),manual('Freight payment is not customs tax payment.')],
 ...['48','49','50','51','52','53'].map(id=>[id,owned('not_applicable'),owned('not_applicable')]),
 ['54',manual('Current date is automatic; authority/signatory of a new declaration is not borrowed from old contracts.'),manual('Current date is automatic; the seller invoice contact is not the declaration signatory.')],
];
const mapCase=(f,index)=>({inputs:f.inputs,comparisonOnly:f.comparisonOnly,positions:rows.map(row=>({graphId:row[0],...row[index],sources:row[index].sources.map(i=>f.inputs[i])}))});
const controls={version:'1.0.0',basis:'Primary document visual/text review; reference outputs are comparison only. Manual means no pre-asserted documentary answer: evidence-backed extra findings are permitted, invented values are not.',cases:{'reference-cpt':mapCase(cpt,1),'reference-fca':mapCase(fca,2)},reportedRegression:{files:[cpt.inputs[7],cpt.inputs[8],cpt.inputs[3],cpt.inputs[2]],forbiddenDocumentNumbers:['24480KGS','USD37454.40'],forbiddenDocumentNames:['ETHYL ACETATE'],requiredGraphs:['2','8','15a','20','22','31','35','42','44'],requiredFacts:['ETHYL ACETATE','37454.40','USD','CPT','777-ЕА']}};
writeFileSync(new URL('./autofill-controls.v1.json',import.meta.url),JSON.stringify(controls,null,2)+'\n');
console.log(JSON.stringify({cases:Object.keys(controls.cases),positions:rows.length}));
