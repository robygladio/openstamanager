<?php

include_once __DIR__.'/../../core.php';

include_once $docroot.'/modules/interventi/modutil.php';
include_once $docroot.'/modules/articoli/modutil.php';
include_once $docroot.'/modules/my_impianti/modutil.php';

switch (post('op')) {
    case 'update':
        $idpreventivo = post('idpreventivo');
        $idcontratto = post('idcontratto');
        $idcontratto_riga = post('idcontratto_riga');

        $idtipointervento = post('idtipointervento');

        $data_richiesta = post('data_richiesta');
        $richiesta = post('richiesta');
        $idsede = post('idsede');

        /*
            Collegamento intervento a preventivo (se impostato)
        */
        // Elimino il collegamento attuale
        $dbo->query('DELETE FROM co_preventivi_interventi WHERE idintervento='.prepare($id_record));
        if (!empty($idpreventivo)) {
            $dbo->insert('co_preventivi_interventi', [
                'idintervento' => $id_record,
                'idpreventivo' => $idpreventivo,
            ]);
        }

        /*
        Collegamento intervento a contratto (se impostato).
        Oltre al collegamento al contratto, l'intervento è collegato ad una riga di pianificazione, perciò è importante considerarla se è impostata
        */
        $array = [
            'idintervento' => $id_record,
            'idtipointervento' => $idtipointervento,
            'data_richiesta' => $data_richiesta,
            'richiesta' => $richiesta,
            'idsede' => $idsede ?: 0,
        ];
        // Creazione nuova pianificazione se non era impostata
        if (!empty($idcontratto) && empty($idcontratto_riga)) {
            // Se questo intervento era collegato ad un altro contratto aggiorno le informazioni...
            $rs = $dbo->fetchArray('SELECT id FROM co_righe_contratti WHERE idintervento='.prepare($id_record));
            if (empty($rs)) {
                $dbo->insert('co_righe_contratti', array_merge(['idcontratto' => $idcontratto], $array));
            }

            // ...altrimenti se sto cambiando contratto aggiorno solo l'id del nuovo contratto
            else {
                $dbo->update('co_righe_contratti', ['idcontratto' => $idcontratto], ['idintervento' => $id_record]);
            }
        }

        // Pianificazione già impostata, aggiorno solo il codice intervento
        elseif (!empty($idcontratto) && !empty($idcontratto_riga)) {
            $dbo->update('co_righe_contratti', $array, ['idcontratto' => $idriga, 'id' => $idcontratto_riga]);
        }

        // Se non è impostato nessun contratto o riga, tolgo il collegamento dell'intervento al contratto
        elseif (empty($idcontratto)) {
            $dbo->update('co_righe_contratti', ['idintervento' => null], ['idintervento' => $id_record]);
        }

        // Aggiorna tutte le sessioni di lavoro
        $lista = (array) post('id_');

        // Limitazione delle azioni dei tecnici
        if ($user['gruppo'] == 'Tecnici') {
            $lista = get_var('Mostra i prezzi al tecnico') && !empty($user['idanagrafica']) ? [$user['idanagrafica']] : [];
        }

        foreach ($lista as $idriga) {
            // Lettura delle date di inizio e fine intervento
            $orario_inizio = post('orario_inizio')[$idriga];
            $orario_fine = post('orario_fine')[$idriga];

            $km = post('km')[$idriga];
            $ore = post('ore')[$idriga];

            // Lettura tariffe in base al tipo di intervento ed al tecnico
            $idtipointervento_tecnico = $post['idtipointerventot'][$idriga];
            $rs = $dbo->fetchArray('SELECT * FROM in_interventi_tecnici WHERE idtecnico='.prepare($post['idtecnico'][$idriga]).' AND idintervento='.prepare($id_record));

            if ($idtipointervento_tecnico != $rs[0]['idtipointervento']) {
                $rsc = $dbo->fetchArray('SELECT * FROM in_tariffe WHERE idtecnico='.prepare($post['idtecnico'][$idriga]).' AND idtipointervento='.prepare($idtipointervento_tecnico));

                if ($rsc[0]['costo_ore'] != 0 || $rsc[0]['costo_km'] != 0 || $rsc[0]['costo_dirittochiamata'] != 0 || $rsc[0]['costo_ore_tecnico'] != 0 || $rsc[0]['costo_km_tecnico'] != 0 || $rsc[0]['costo_dirittochiamata_tecnico'] != 0) {
                    $prezzo_ore_unitario = $rsc[0]['costo_ore'];
                    $prezzo_km_unitario = $rsc[0]['costo_km'];
                    $prezzo_dirittochiamata = $rsc[0]['costo_dirittochiamata'];

                    $prezzo_ore_unitario_tecnico = $rsc[0]['costo_ore_tecnico'];
                    $prezzo_km_unitario_tecnico = $rsc[0]['costo_km_tecnico'];
                    $prezzo_dirittochiamata_tecnico = $rsc[0]['costo_dirittochiamata_tecnico'];
                }

                // ...altrimenti se non c'è una tariffa per il tecnico leggo i costi globali
                else {
                    $rsc = $dbo->fetchArray('SELECT * FROM in_tipiintervento WHERE idtipointervento='.prepare($idtipointervento_tecnico));

                    $prezzo_ore_unitario = $rsc[0]['costo_orario'];
                    $prezzo_km_unitario = $rsc[0]['costo_km'];
                    $prezzo_dirittochiamata = $rsc[0]['costo_diritto_chiamata'];

                    $prezzo_ore_unitario_tecnico = $rsc[0]['costo_orario_tecnico'];
                    $prezzo_km_unitario_tecnico = $rsc[0]['costo_km_tecnico'];
                    $prezzo_dirittochiamata_tecnico = $rsc[0]['costo_diritto_chiamata_tecnico'];
                }
            } else {
                $prezzo_ore_unitario = $rs[0]['prezzo_ore_unitario'];
                $prezzo_km_unitario = $rs[0]['prezzo_km_unitario'];
                $prezzo_dirittochiamata = $rs[0]['prezzo_dirittochiamata'];
                $prezzo_ore_unitario_tecnico = $rs[0]['prezzo_ore_unitario_tecnico'];
                $prezzo_km_unitario_tecnico = $rs[0]['prezzo_km_unitario_tecnico'];
                $prezzo_dirittochiamata_tecnico = $rs[0]['prezzo_dirittochiamata_tecnico'];
            }

            // Totali
            $prezzo_ore_consuntivo = $prezzo_ore_unitario * $ore + $prezzo_dirittochiamata;
            $prezzo_km_consuntivo = $prezzo_km_unitario * $km;

            $prezzo_ore_consuntivo_tecnico = $prezzo_ore_unitario_tecnico * $ore + $prezzo_dirittochiamata_tecnico;
            $prezzo_km_consuntivo_tecnico = $prezzo_km_unitario_tecnico * $km;

            // Sconti
            $sconto_unitario = post('sconto')[$idriga];
            $tipo_sconto = post('tipo_sconto')[$idriga];
            $sconto = ($tipo_sconto == 'PRC') ? ($prezzo_ore_consuntivo * $sconto_unitario) / 100 : $sconto_unitario;

            $scontokm_unitario = post('scontokm')[$idriga];
            $tipo_scontokm = post('tipo_scontokm')[$idriga];
            $scontokm = ($tipo_scontokm == 'PRC') ? ($prezzo_km_consuntivo * $sconto_unitario) / 100 : $scontokm_unitario;

            $dbo->update('in_interventi_tecnici', [
                'idintervento' => $id_record,
                'idtipointervento' => $idtipointervento_tecnico,
                'idtecnico' => post('idtecnico')[$idriga],

                'orario_inizio' => $orario_inizio,
                'orario_fine' => $orario_fine,
                'ore' => $ore,
                'km' => $km,

                'prezzo_ore_unitario' => $prezzo_ore_unitario,
                'prezzo_km_unitario' => $prezzo_km_unitario,
                'prezzo_dirittochiamata' => $prezzo_dirittochiamata,
                'prezzo_ore_unitario_tecnico' => $prezzo_ore_unitario_tecnico,
                'prezzo_km_unitario_tecnico' => $prezzo_km_unitario_tecnico,
                'prezzo_dirittochiamata_tecnico' => $prezzo_dirittochiamata_tecnico,

                'prezzo_ore_consuntivo' => $prezzo_ore_consuntivo,
                'prezzo_km_consuntivo' => $prezzo_km_consuntivo,
                'prezzo_ore_consuntivo_tecnico' => $prezzo_ore_consuntivo_tecnico,
                'prezzo_km_consuntivo_tecnico' => $prezzo_km_consuntivo_tecnico,

                'sconto' => $sconto,
                'sconto_unitario' => $sconto_unitario,
                'tipo_sconto' => $tipo_sconto,

                'scontokm' => $scontokm,
                'scontokm_unitario' => $scontokm_unitario,
                'tipo_scontokm' => $tipo_scontokm,
            ], ['id' => $idriga]);
        }

        $tipo_sconto = $post['tipo_sconto_globale'];
        $sconto = $post['sconto_globale'];

        // Salvataggio modifiche intervento
        $dbo->update('in_interventi', [
            'data_richiesta' => $data_richiesta,
            'richiesta' => $richiesta,
            'descrizione' => post('descrizione'),
            'informazioniaggiuntive' => post('informazioniaggiuntive'),

            'idanagrafica' => post('idanagrafica'),
            'idclientefinale' => post('idclientefinale'),
            'idreferente' => post('idreferente'),
            'idtipointervento' => $idtipointervento,

            'idstatointervento' => post('idstatointervento'),
            'idsede' => $idsede,
            'idautomezzo' => post('idautomezzo'),

            'sconto_globale' => $sconto,
            'tipo_sconto_globale' => $tipo_sconto,
        ], ['id' => $id_record]);

        $_SESSION['infos'][] = tr('Informazioni salvate correttamente!');

        break;

    case 'add':
        /*
        $codice = post('codice');

        // Controlli sul codice
        $count = -1;
        do {
            $new_codice = ($count < 0) ? $codice : get_next_code($codice, 1, get_var('Formato codice intervento'));
            $rs = $dbo->fetchArray('SELECT codice FROM in_interventi WHERE codice='.prepare($new_codice));
            ++$count;
        } while (!empty($rs) || empty($new_codice));

        if ($count > 0) {
            $_SESSION['warnings'][] = tr('Numero intervento _NUM_ saltato perchè già esistente!', [
                '_NUM_' => "'".$codice."'"
            ]);
            $_SESSION['warnings'][] = tr('Nuovo numero intervento calcolato _NUM_', [
                '_NUM_' => "'".$new_codice."'"
            ]);
        }
        */
        $formato = get_var('Formato codice intervento');

        // Condizioni aggiuntive: WHERE concat("", codice * 1) = codice AND LENGTH(codice) = '.strlen($formato).'
        $rs = $dbo->fetchArray('SELECT codice FROM in_interventi ORDER BY id DESC LIMIT 1');
        $codice = get_next_code($rs[0]['codice'], 1, $formato);

        // Informazioni di base
        $idpreventivo = post('idpreventivo');
        $idcontratto = post('idcontratto');
        $idcontratto_riga = post('idcontratto_riga');
        $idtipointervento = post('idtipointervento');
        $idsede = post('idsede');
        $data_richiesta = post('data_richiesta');
        $richiesta = post('richiesta');

        if (!empty($codice) && !empty($post['idanagrafica']) && !empty($post['idtipointervento'])) {
            // Salvataggio modifiche intervento
            $dbo->insert('in_interventi', [
                'idanagrafica' => post('idanagrafica'),
                'idclientefinale' => post('idclientefinale') ?: 0,
                'idstatointervento' => post('idstatointervento'),
                'idtipointervento' => $idtipointervento,
                'idsede' => $idsede ?: 0,
                'idautomezzo' => $idautomezzo ?: 0,

                'codice' => $codice,
                'data_richiesta' => $data_richiesta,
                'richiesta' => $richiesta,
            ]);

            $id_record = $dbo->lastInsertedID();

            $_SESSION['infos'][] = tr('Aggiunto nuovo intervento!');
        }

        // Collego l'intervento al preventivo
        if (!empty($idpreventivo)) {
            $dbo->insert('co_preventivi_interventi', [
                'idintervento' => $id_record,
                'idpreventivo' => $idpreventivo,
            ]);
        }

        // Collego l'intervento al contratto
        if (!empty($idcontratto)) {
            $array = [
                'idintervento' => $id_record,
                'idtipointervento' => $idtipointervento,
                'data_richiesta' => $data_richiesta,
                'richiesta' => $richiesta,
                'idsede' => $idsede ?: 0,
            ];

            // Se è specificato che l'intervento fa parte di una pianificazione aggiorno il codice dell'intervento sulla riga della pianificazione
            if (!empty($idcontratto_riga)) {
                $dbo->update('co_righe_contratti', $array, ['idcontratto' => $idcontratto, 'id' => $idcontratto_riga]);
            }
            // Altrimenti inserisco una nuova pianificazione e collego l'intervento
            else {
                $dbo->insert('co_righe_contratti', array_merge(['idcontratto' => $idcontratto], $array));
            }
        }

        if (!empty($post['idordineservizio'])) {
            $dbo->query('UPDATE co_ordiniservizio SET idintervento='.prepare($id_record).' WHERE id='.prepare($post['idordineservizio']));
        }

        // Collegamenti tecnici/interventi
        $idtecnici = post('idtecnico');
        $data = post('data');

        foreach ($idtecnici as $idtecnico) {
            add_tecnico($id_record, $idtecnico, $data.' '.post('orario_inizio'), $data.' '.post('orario_fine'), $idcontratto);
        }

        // Collegamenti intervento/impianti
        $impianti = (array) post('idimpianti');
        if (!empty($impianti)) {
            foreach ($impianti as $impianto) {
                $dbo->insert('my_impianti_interventi', [
                    'idintervento' => $id_record,
                    'idimpianto' => $impianto,
                ]);
            }

            // Collegamenti intervento/componenti
            $componenti = (array) post('componenti');
            foreach ($componenti as $componente) {
                $dbo->insert('my_componenti_interventi', [
                    'id_intervento' => $id_record,
                    'id_componente' => $componente,
                ]);
            }
        }

        if (post('ref') == 'dashboard') {
            $_SESSION['infos'] = [];
            $_SESSION['warnings'] = [];
        }

        break;

    // Eliminazione intervento
    case 'delete':
        // Elimino anche eventuali file caricati
        $rs = $dbo->fetchArray('SELECT filename FROM zz_files WHERE id_module='.prepare($id_module).' AND id='.prepare($id_record));

        for ($i = 0; $i < count($rs); ++$i) {
            delete($docroot.'/files/interventi/'.$rs[$i]['filename']);
        }

        $dbo->query('DELETE FROM zz_files WHERE id_module='.prepare($id_module).' AND id='.prepare($id_record));

        $codice = $dbo->fetchArray('SELECT codice FROM in_interventi WHERE id='.prepare($id_record))[0]['codice'];

        /*
            Riporto in magazzino gli articoli presenti nell'intervento in cancellazine
        */
        // Leggo la quantità attuale nell'intervento
        $q = 'SELECT qta, idautomezzo, idarticolo FROM mg_articoli_interventi WHERE idintervento='.prepare($id_record);
        $rs = $dbo->fetchArray($q);

        for ($i = 0; $i < count($rs); ++$i) {
            $qta = $rs[$i]['qta'];
            $idautomezzo = $rs[$i]['idautomezzo'];
            $idarticolo = $rs[$i]['idarticolo'];

            add_movimento_magazzino($idarticolo, $qta, ['idautomezzo' => $idautomezzo, 'idintervento' => $id_record]);
        }

        // Eliminazione associazioni tra interventi e contratti
        $query = 'UPDATE co_righe_contratti SET idintervento = NULL WHERE idintervento='.prepare($id_record);
        $dbo->query($query);

        // Eliminazione dell'intervento
        $query = 'DELETE FROM in_interventi WHERE id='.prepare($id_record).' '.Modules::getAdditionalsQuery($id_module);
        $dbo->query($query);

        // Elimino i collegamenti degli articoli a questo intervento
        $dbo->query('DELETE FROM mg_articoli_interventi WHERE idintervento='.prepare($id_record));

        // Elimino il collegamento al componente
        $dbo->query('DELETE FROM my_impianto_componenti WHERE idintervento='.prepare($id_record));

        // Eliminazione associazione tecnici collegati all'intervento
        $query = 'DELETE FROM in_interventi_tecnici WHERE idintervento='.prepare($id_record);
        $dbo->query($query);

        // Eliminazione associazioni tra interventi e preventivi
        $query = 'DELETE FROM co_preventivi_interventi WHERE idintervento='.prepare($id_record);
        $dbo->query($query);

        // Eliminazione righe aggiuntive dell'intervento
        $query = 'DELETE FROM in_righe_interventi WHERE idintervento='.prepare($id_record);
        $dbo->query($query);

        // Eliminazione associazione interventi e articoli
        $query = 'DELETE FROM mg_articoli_interventi WHERE idintervento='.prepare($id_record);
        $dbo->query($query);

        // Eliminazione associazione interventi e my_impianti
        $query = 'DELETE FROM my_impianti_interventi WHERE idintervento='.prepare($id_record);
        $dbo->query($query);

        // Eliminazione movimenti riguardanti l'intervento cancellato
        $dbo->query('DELETE FROM mg_movimenti WHERE idintervento='.prepare($id_record));

        $_SESSION['infos'][] = tr('Intervento _NUM_ eliminato!', [
            '_NUM_' => "'".$codice."'",
        ]);

        break;

    /*
        Gestione righe generiche
    */
    case 'addriga':
        $descrizione = post('descrizione');
        $qta = post('qta');
        $um = post('um');
        $prezzo_vendita = post('prezzo_vendita');
        $prezzo_acquisto = post('prezzo_acquisto');

        $sconto_unitario = $post['sconto'];
        $tipo_sconto = $post['tipo_sconto'];
        $sconto = ($tipo_sconto == 'PRC') ? ($prezzo_vendita * $sconto_unitario) / 100 : $sconto_unitario;
        $sconto = $sconto * $qta;

        $dbo->query('INSERT INTO in_righe_interventi(descrizione, qta, um, prezzo_vendita, prezzo_acquisto, sconto, sconto_unitario, tipo_sconto, idintervento) VALUES ('.prepare($descrizione).', '.prepare($qta).', '.prepare($um).', '.prepare($prezzo_vendita).', '.prepare($prezzo_acquisto).', '.prepare($sconto).', '.prepare($sconto_unitario).', '.prepare($tipo_sconto).', '.prepare($id_record).')');

        break;

    case 'editriga':
        $idriga = post('idriga');
        $descrizione = post('descrizione');
        $qta = post('qta');
        $um = post('um');
        $prezzo_vendita = post('prezzo_vendita');
        $prezzo_acquisto = post('prezzo_acquisto');

        $sconto_unitario = $post['sconto'];
        $tipo_sconto = $post['tipo_sconto'];
        $sconto = ($tipo_sconto == 'PRC') ? ($prezzo_vendita * $sconto_unitario) / 100 : $sconto_unitario;
        $sconto = $sconto * $qta;

        $dbo->query('UPDATE in_righe_interventi SET '.
            ' descrizione='.prepare($descrizione).','.
            ' qta='.prepare($qta).','.
            ' um='.prepare($um).','.
            ' prezzo_vendita='.prepare($prezzo_vendita).','.
            ' prezzo_acquisto='.prepare($prezzo_acquisto).','.
            ' sconto='.prepare($sconto).','.
            ' sconto_unitario='.prepare($sconto_unitario).','.
            ' tipo_sconto='.prepare($tipo_sconto).
            ' WHERE id='.prepare($idriga));

        break;

    case 'delriga':
        $idriga = post('idriga');
        $dbo->query('DELETE FROM in_righe_interventi WHERE id='.prepare($idriga).' '.Modules::getAdditionalsQuery($id_module));

        break;

    /*
        GESTIONE ARTICOLI
    */

    case 'editarticolo':
        $idriga = post('idriga');
        $idarticolo = post('idarticolo');
        $idimpianto = post('idimpianto');
        $idautomezzo = post('idautomezzo');

        $idarticolo_originale = post('idarticolo_originale');

        // Leggo la quantità attuale nell'intervento
        $q = 'SELECT qta, idautomezzo, idimpianto FROM mg_articoli_interventi WHERE idarticolo='.prepare($idarticolo_originale).' AND idintervento='.prepare($id_record);
        $rs = $dbo->fetchArray($q);
        $old_qta = $rs[0]['qta'];
        $idimpianto = $rs[0]['idimpianto'];
        $idautomezzo = $rs[0]['idautomezzo'];

        $serials = array_column($dbo->select('mg_prodotti', 'serial', ['id_riga_intervento' => $idriga]), 'serial');

        add_movimento_magazzino($idarticolo_originale, $old_qta, ['idautomezzo' => $idautomezzo, 'idintervento' => $id_record]);

        // Elimino questo articolo dall'intervento
        $dbo->query('DELETE FROM mg_articoli_interventi WHERE id='.prepare($idriga));

        // Elimino il collegamento al componente
        $dbo->query('DELETE FROM my_impianto_componenti WHERE idimpianto='.prepare($idimpianto).' AND idintervento='.prepare($id_record));

        /* Ricollego l'articolo modificato all'intervento */
        /* ci può essere il caso in cui cambio idarticolo e anche qta */

        // no break
    case 'addarticolo':
        $idarticolo = post('idarticolo');
        $idautomezzo = post('idautomezzo');
        $descrizione = post('descrizione');
        $idimpianto = post('idimpianto');
        $qta = post('qta');
        $um = post('um');
        $prezzo_vendita = post('prezzo_vendita');

        $sconto_unitario = $post['sconto'];
        $tipo_sconto = $post['tipo_sconto'];
        $sconto = ($tipo_sconto == 'PRC') ? ($prezzo_vendita * $sconto_unitario) / 100 : $sconto_unitario;
        $sconto = $sconto * $qta;

        // Decremento la quantità
        add_movimento_magazzino($idarticolo, -$qta, ['idautomezzo' => $idautomezzo, 'idintervento' => $id_record]);

        // Aggiorno l'automezzo dell'intervento
        $dbo->query('UPDATE in_interventi SET idautomezzo='.prepare($idautomezzo).' WHERE id='.prepare($id_record).' '.Modules::getAdditionalsQuery($id_module));

        $rsart = $dbo->fetchArray('SELECT abilita_serial, prezzo_acquisto FROM mg_articoli WHERE id='.prepare($idarticolo));
        $prezzo_acquisto = $rsart[0]['prezzo_acquisto'];

        // Aggiunto il collegamento fra l'articolo e l'intervento
        $idriga = $dbo->query('INSERT INTO mg_articoli_interventi(idarticolo, idintervento, idimpianto, idautomezzo, descrizione, prezzo_vendita, prezzo_acquisto, sconto, sconto_unitario, tipo_sconto, idiva_vendita, qta, um, abilita_serial) VALUES ('.prepare($idarticolo).', '.prepare($id_record).', '.(empty($idimpianto) ? 'NULL' : prepare($idimpianto)).', '.prepare($idautomezzo).', '.prepare($descrizione).', '.prepare($prezzo_vendita).', '.prepare($prezzo_acquisto).', '.prepare($sconto).', '.prepare($sconto_unitario).', '.prepare($tipo_sconto).', (SELECT idiva_vendita FROM mg_articoli WHERE id='.prepare($idarticolo).'), '.prepare($qta).', '.prepare($um).', '.prepare($rsart[0]['abilita_serial']).')');

        if (!empty($serials)) {
            if ($old_qta > $qta) {
                $serials = array_slice($serials, 0, $qta);
            }

            $dbo->sync('mg_prodotti', ['id_riga_intervento' => $idriga, 'dir' => 'entrata', 'id_articolo' => $idarticolo], ['serial' => $serials]);
        }

        link_componente_to_articolo($id_record, $idimpianto, $idarticolo, $qta);

        break;

    case 'unlink_articolo':
        $idriga = post('idriga');
        $idarticolo = post('idarticolo');

        // Riporto la merce nel magazzino
        if (!empty($idriga) && !empty($id_record)) {
            // Leggo la quantità attuale nell'intervento
            $q = 'SELECT qta, idautomezzo, idarticolo, idimpianto FROM mg_articoli_interventi WHERE id='.prepare($idriga);
            $rs = $dbo->fetchArray($q);
            $qta = $rs[0]['qta'];
            $idarticolo = $rs[0]['idarticolo'];
            $idimpianto = $rs[0]['idimpianto'];
            $idautomezzo = $rs[0]['idautomezzo'];

            add_movimento_magazzino($idarticolo, $qta, ['idautomezzo' => $idautomezzo, 'idintervento' => $id_record]);

            // Elimino questo articolo dall'intervento
            $dbo->query('DELETE FROM mg_articoli_interventi WHERE id='.prepare($idriga).' AND idintervento='.prepare($id_record));

            // Elimino il collegamento al componente
            $dbo->query('DELETE FROM my_impianto_componenti WHERE idimpianto='.prepare($idimpianto).' AND idintervento='.prepare($id_record));

            // Elimino i seriali utilizzati dalla riga
            $dbo->query('DELETE FROM `mg_prodotti` WHERE id_articolo = '.prepare($idarticolo).' AND id_riga_intervento = '.prepare($id_record));
        }

        break;

    case 'add_serial':
        $idriga = $post['idriga'];
        $idarticolo = $post['idarticolo'];

        $serials = (array) $post['serial'];
        foreach ($serials as $key => $value) {
            if (empty($value)) {
                unset($serials[$key]);
            }
        }

        $dbo->sync('mg_prodotti', ['id_riga_intervento' => $idriga, 'dir' => 'entrata', 'id_articolo' => $idarticolo], ['serial' => $serials]);

        break;

    case 'firma':
        if (directory($docroot.'/files/interventi')) {
            if (post('firma_base64') != '') {
                // Salvataggio firma
                $firma_file = 'firma_'.time().'.png';
                $firma_nome = post('firma_nome');

                $data = explode(',', post('firma_base64'));

                $img = Intervention\Image\ImageManagerStatic::make(base64_decode($data[1]));
                $img->resize(680, 202, function ($constraint) {
                    $constraint->aspectRatio();
                });

                if (!$img->save($docroot.'/files/interventi/'.$firma_file)) {
                    $_SESSION['errors'][] = tr('Impossibile creare il file!');
                } elseif ($dbo->query('UPDATE in_interventi SET firma_file='.prepare($firma_file).', firma_data=NOW(), firma_nome = '.prepare($firma_nome).', idstatointervento = (SELECT idstatointervento FROM in_statiintervento WHERE completato = 1 LIMIT 0, 1) WHERE id='.prepare($id_record))) {
                    $_SESSION['infos'][] = tr('Firma salvata correttamente!');
                    $_SESSION['infos'][] = tr('Attività completata!');
                } else {
                    $_SESSION['errors'][] = tr('Errore durante il salvataggio della firma nel database!');
                }
            } else {
                $_SESSION['errors'][] = tr('Errore durante il salvataggio della firma!').tr('La firma risulta vuota').'...';
            }
        } else {
            $_SESSION['errors'][] = tr("Non è stato possibile creare la cartella _DIRECTORY_ per salvare l'immagine della firma!", [
                '_DIRECTORY_' => '<b>/files/interventi</b>',
            ]);
        }

        break;

    case 'sendemail':
        $from_address = post('from_address');
        $from_name = post('from_name');

        $destinatario = post('destinatario');
        $cc = get_var('Destinatario fisso in copia (campo CC)');

        $oggetto = html_entity_decode(post('oggetto'));
        $testo_email = post('body');
        $allegato = post('allegato');

        $mail = new Mail();

        $mail->AddReplyTo($from_address, $from_name);
        $mail->SetFrom($from_address, $from_name);

        $mail->AddAddress($destinatario, '');

        // se ho impostato la conferma di lettura
        if (post('confermalettura') == 'on') {
            $mail->ConfirmReadingTo = $from_address;
        }

        $mail->Subject = $oggetto;
        $mail->AddCC($cc);

        $mail->MsgHTML($testo_email);

        if (!empty($allegato)) {
            $mail->AddAttachment($allegato);
        }

        if (!$mail->send()) {
            $_SESSION['errors'][] = tr("Errore durante l'invio dell'email").': '.$mail->ErrorInfo;
        } else {
            $dbo->query('UPDATE in_interventi SET data_invio=NOW() WHERE id='.prepare($id_record));
            $_SESSION['infos'][] = tr('Email inviata!');
        }

        break;
}
