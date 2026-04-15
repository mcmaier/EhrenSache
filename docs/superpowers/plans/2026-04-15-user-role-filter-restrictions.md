# User Role Filter Restrictions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Normale Benutzer (Rolle `user`) sehen in Statistik und Anwesenheit nur die Filter, die ihrer Gruppenzugehörigkeit entsprechen.

**Architecture:** PHP-Backend gibt `group_ids` auch für non-Admin-Einzelabrufe zurück. Ein neuer `getUserGroupIds()`-Helper in `members.js` liest die Gruppen aus dem Cache. `statistics.js` und `records.js` nutzen diesen Helper, um Dropdowns zu filtern und unzulässige Filter auszublenden.

**Tech Stack:** PHP 8+, Vanilla JS ES6 Modules, MySQL, kein Build-Step

---

## Betroffene Dateien

| Datei | Änderung |
|-------|----------|
| `private/handlers/members.php` | `group_ids` in non-Admin-Einzelabruf ergänzen (Zeilen 48–68) |
| `public/js/modules/members.js` | `group_ids_array` für non-Admin-Zweig + `getUserGroupIds()` exportieren |
| `public/js/modules/statistics.js` | Import erweitern + Gruppenfilter auf User-Gruppen einschränken |
| `public/js/modules/records.js` | Import erweitern + Terminart-Filter filtern + Termin-Filter ausblenden |

---

## Task 1: PHP — `group_ids` für non-Admin-Einzelabruf

**Files:**
- Modify: `private/handlers/members.php:48-68`

- [ ] **Schritt 1: SQL-Query und Response erweitern**

  Ersetze in `private/handlers/members.php` den non-Admin `else`-Block (Zeilen 47–69):

  ```php
  else{
      $memberId = $authMemberId; 
      $stmt = $db->prepare("SELECT name, surname, member_number FROM {$prefix}members WHERE member_id = ?");
      $stmt->execute([$memberId]);
      $member = $stmt->fetch(PDO::FETCH_ASSOC);                            

      $warning = null;
      if( $id!= $memberId) {
          $warning = "member_id ignored - you can only get your own linked member number (ID: $memberId)";
      }

      if($member)
      {
          echo json_encode([  "name" => $member['name'],
                              "surname" => $member['surname'],
                              "member_number" => $member['member_number'],
                              "warning" => $warning]);
      }
      else {             
          http_response_code(404);
          echo json_encode(["message" => "Member not found"]);
      }
  }
  ```

  **durch:**

  ```php
  else{
      $memberId = $authMemberId;
      $stmt = $db->prepare("
          SELECT m.name, m.surname, m.member_number,
                 GROUP_CONCAT(mga.group_id SEPARATOR ', ') as group_ids
          FROM {$prefix}members m
          LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
          WHERE m.member_id = ?
          GROUP BY m.member_id
      ");
      $stmt->execute([$memberId]);
      $member = $stmt->fetch(PDO::FETCH_ASSOC);

      $warning = null;
      if ($id != $memberId) {
          $warning = "member_id ignored - you can only get your own linked member number (ID: $memberId)";
      }

      if ($member) {
          echo json_encode([
              "name"          => $member['name'],
              "surname"       => $member['surname'],
              "member_number" => $member['member_number'],
              "group_ids"     => $member['group_ids'],
              "warning"       => $warning
          ]);
      } else {
          http_response_code(404);
          echo json_encode(["message" => "Member not found"]);
      }
  }
  ```

- [ ] **Schritt 2: Manuell verifizieren**

  Als eingeloggter `user` (nicht Admin) im Browser-DevTools prüfen:
  ```
  GET /EhrenSache/public/api/api.php?resource=members&id=<eigene_member_id>
  ```
  Erwartete Antwort enthält jetzt `"group_ids": "1, 3"` (oder `null` wenn keine Gruppen).

- [ ] **Schritt 3: Commit**

  ```bash
  git add private/handlers/members.php
  git commit -m "feat: return group_ids for non-admin member self-fetch"
  ```

---

## Task 2: JS — `group_ids_array` für non-Admin + `getUserGroupIds()` Helper

**Files:**
- Modify: `public/js/modules/members.js:72-76` (non-Admin-Zweig in `loadMembers()`)
- Modify: `public/js/modules/members.js` (neue Funktion nach `loadMembers()`)

- [ ] **Schritt 1: `group_ids_array` im non-Admin-Zweig berechnen**

  In `public/js/modules/members.js`, ersetze den non-Admin-Zweig (Zeilen 72–76):

  ```js
  else {        
      if (userDetails && userDetails.member_id) {
          const member = await apiCall('members', 'GET', null, { id: userDetails.member_id });
          members = member ? [member] : [];
      } 
  }
  ```

  **durch:**

  ```js
  else {
      if (userDetails && userDetails.member_id) {
          const member = await apiCall('members', 'GET', null, { id: userDetails.member_id });
          members = member ? [member] : [];
      }
      // group_ids_array aus group_ids-String berechnen (analog zum Admin-Zweig)
      members.forEach(member => {
          if (member.group_ids && typeof member.group_ids === 'string') {
              member.group_ids_array = member.group_ids
                  .split(',')
                  .map(id => parseInt(id.trim()));
          } else {
              member.group_ids_array = [];
          }
      });
  }
  ```

- [ ] **Schritt 2: `getUserGroupIds()` exportieren**

  Direkt nach der schließenden `}` von `loadMembers()` (nach Zeile 89) einfügen:

  ```js
  /**
   * Gibt die group_ids des eingeloggten Users zurück.
   * - null  → Admin/Manager (keine Einschränkung)
   * - []    → User ohne verknüpftes Mitglied oder ohne Gruppen
   * - [1,3] → User mit diesen Gruppen
   */
  export async function getUserGroupIds() {
      if (isAdminOrManager) return null;
      const members = await loadMembers();
      if (!members || members.length === 0) return [];
      return members[0].group_ids_array || [];
  }
  ```

- [ ] **Schritt 3: Manuell verifizieren**

  In der Browser-Konsole als normaler User:
  ```js
  import('/EhrenSache/public/js/modules/members.js').then(m => m.getUserGroupIds().then(console.log));
  ```
  Erwartetes Ergebnis: Array mit Gruppen-IDs, z.B. `[1, 3]`.

  Als Admin: Ergebnis ist `null`.

- [ ] **Schritt 4: Commit**

  ```bash
  git add public/js/modules/members.js
  git commit -m "feat: add group_ids_array for non-admin members and getUserGroupIds() helper"
  ```

---

## Task 3: Statistik — Gruppenfilter auf User-Gruppen einschränken

**Files:**
- Modify: `public/js/modules/statistics.js:11` (Import)
- Modify: `public/js/modules/statistics.js:50-97` (`loadStatisticsFilters()`)

- [ ] **Schritt 1: Import erweitern**

  In `public/js/modules/statistics.js` Zeile 13, ersetze:

  ```js
  import { loadMembers } from './members.js';
  ```

  **durch:**

  ```js
  import { loadMembers, getUserGroupIds } from './members.js';
  ```

- [ ] **Schritt 2: Gruppenfilter einschränken**

  In `loadStatisticsFilters()`, ersetze den Block, der das `statGroup`-Dropdown befüllt (Zeilen 56–68):

  ```js
  const groupSelect = document.getElementById('statGroup');
  if (groupSelect) {
      const currentValue = groupSelect.value;
      
      groupSelect.innerHTML = '<option value="">Alle Gruppen</option>';
      if (groups && groups.length > 0) {
          groups.forEach(group => {
              groupSelect.innerHTML += `<option value="${group.group_id}">${group.group_name}</option>`;
          });
      }
      
      if (currentValue) groupSelect.value = currentValue;
  }
  ```

  **durch:**

  ```js
  const groupSelect = document.getElementById('statGroup');
  if (groupSelect) {
      const currentValue = groupSelect.value;

      groupSelect.innerHTML = '<option value="">Alle Gruppen</option>';
      if (groups && groups.length > 0) {
          groups.forEach(group => {
              groupSelect.innerHTML += `<option value="${group.group_id}">${group.group_name}</option>`;
          });
      }

      if (currentValue) groupSelect.value = currentValue;

      // Für non-Admin: nur eigene Gruppen anzeigen
      const userGroupIds = await getUserGroupIds();
      if (userGroupIds !== null) {
          Array.from(groupSelect.options).forEach(opt => {
              if (opt.value !== '' && !userGroupIds.includes(parseInt(opt.value))) {
                  opt.remove();
              }
          });
          // Automatisch vorauswählen wenn nur eine Gruppe vorhanden
          if (!groupSelect.value && groupSelect.options.length === 2) {
              groupSelect.selectedIndex = 1;
          }
      }
  }
  ```

- [ ] **Schritt 3: Manuell verifizieren**

  Als normaler User Statistik-Sektion öffnen. Das Gruppen-Dropdown darf nur die eigenen Gruppen enthalten. Als Admin bleibt das Dropdown unverändert (alle Gruppen).

- [ ] **Schritt 4: Commit**

  ```bash
  git add public/js/modules/statistics.js
  git commit -m "feat: restrict statistics group filter to user's own groups"
  ```

---

## Task 4: Anwesenheit — Terminart-Filter filtern + Termin-Filter ausblenden

**Files:**
- Modify: `public/js/modules/records.js:12` (Import)
- Modify: `public/js/modules/records.js:353-406` (`loadRecordFilters()`)

- [ ] **Schritt 1: Import erweitern**

  In `public/js/modules/records.js` Zeile 14, ersetze:

  ```js
  import { loadMembers } from './members.js';
  ```

  **durch:**

  ```js
  import { loadMembers, getUserGroupIds } from './members.js';
  ```

- [ ] **Schritt 2: Terminart-Filter für non-Admin einschränken**

  In `loadRecordFilters()`, direkt **vor** dem Befüllen des `aptTypeSelect` (vor Zeile 369), `getUserGroupIds()` laden:

  Ersetze den Beginn von `loadRecordFilters()` nach `isLoadingFilters = true;` (Zeilen 363–398):

  ```js
  isLoadingFilters = true;

  // Gruppen laden
  const aptTypes = await loadTypes(forceReload);

  // Gruppen-Filter befüllen
  const aptTypeSelect = document.getElementById('filterAptType');
  const currentAptTypeValue = aptTypeSelect.value;
  
  aptTypeSelect.innerHTML = '<option value="">Alle Termine</option>';
  if (aptTypes && aptTypes.length > 0) {
      aptTypes.forEach(aptt => {
         
          // Terminart-Anzeige im Dropdown-Text
          let displayText = `${aptt.type_name}`;
          
          // Erstelle Option mit data-Attributen
          const option = document.createElement('option');
          option.value = aptt.type_id;
          option.textContent = displayText;

          // Füge Farbe hinzu (funktioniert in den meisten Browsern)
          if (aptt.color) {
              option.style.color = aptt.color;
              option.style.fontWeight = '500';
          }
          
          // Speichere Type-Daten für Badge-Anzeige
          option.dataset.typeId = aptt.type_id || '';
          option.dataset.typeName = aptt.type_name || '';
          
          aptTypeSelect.appendChild(option); 
      });
  }
  aptTypeSelect.value = currentAptTypeValue;
  ```

  **durch:**

  ```js
  isLoadingFilters = true;

  // Gruppen laden
  const aptTypes = await loadTypes(forceReload);
  const userGroupIds = await getUserGroupIds();

  // Terminart-Filter befüllen
  const aptTypeSelect = document.getElementById('filterAptType');
  const currentAptTypeValue = aptTypeSelect.value;

  aptTypeSelect.innerHTML = '<option value="">Alle Termine</option>';
  if (aptTypes && aptTypes.length > 0) {
      aptTypes.forEach(aptt => {
          // Für non-Admin: nur Terminarten mit passender Gruppenverknüpfung anzeigen
          if (userGroupIds !== null) {
              // Typen ohne Gruppen ausblenden (noch nicht konfiguriert)
              if (!aptt.groups || aptt.groups.length === 0) return;
              // Nur Typen anzeigen, die mindestens eine der eigenen Gruppen haben
              const hasMatch = aptt.groups.some(g => userGroupIds.includes(g.group_id));
              if (!hasMatch) return;
          }

          // Terminart-Anzeige im Dropdown-Text
          let displayText = `${aptt.type_name}`;

          // Erstelle Option mit data-Attributen
          const option = document.createElement('option');
          option.value = aptt.type_id;
          option.textContent = displayText;

          // Füge Farbe hinzu (funktioniert in den meisten Browsern)
          if (aptt.color) {
              option.style.color = aptt.color;
              option.style.fontWeight = '500';
          }

          // Speichere Type-Daten für Badge-Anzeige
          option.dataset.typeId = aptt.type_id || '';
          option.dataset.typeName = aptt.type_name || '';

          aptTypeSelect.appendChild(option);
      });
  }
  aptTypeSelect.value = currentAptTypeValue;
  ```

- [ ] **Schritt 3: Termin-Filter für non-Admin ausblenden**

  Direkt nach `aptTypeSelect.value = currentAptTypeValue;` (nach dem Block aus Schritt 2) einfügen:

  ```js
  // Termin-Filter für non-Admin ausblenden
  const appointmentFilterGroup = document.getElementById('filterAppointment')?.closest('.form-group');
  if (appointmentFilterGroup) {
      appointmentFilterGroup.style.display = isAdminOrManager ? '' : 'none';
  }
  ```

- [ ] **Schritt 4: Manuell verifizieren — non-Admin**

  Als normaler User Anwesenheits-Sektion öffnen:
  - Terminart-Dropdown zeigt nur Arten, die der eigenen Gruppe(n) zugewiesen sind
  - Terminart-Dropdown zeigt keine Arten ohne Gruppenverknüpfung
  - "Filter nach Termin"-Dropdown ist nicht sichtbar

- [ ] **Schritt 5: Manuell verifizieren — Admin**

  Als Admin Anwesenheits-Sektion öffnen:
  - Alle Terminarten sichtbar (auch solche ohne Gruppen)
  - "Filter nach Termin"-Dropdown sichtbar

- [ ] **Schritt 6: Commit**

  ```bash
  git add public/js/modules/records.js
  git commit -m "feat: filter appointment type dropdown and hide appointment filter for regular users"
  ```
