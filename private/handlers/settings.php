<?php

// ============================================
// SETTINGS Controller
// ============================================
function handleSettings($db, $method, $authUserId, $authUserRole) {

    // Nur Admins dürfen auf Einstellungen zugreifen
    requireAdmin();

    try {
        switch ($method) {
            case 'GET':
                // Alle Einstellungen abrufen
                $stmt = $db->query("SELECT * FROM system_settings ORDER BY category, setting_key");
                $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['settings' => $settings]);
                break;

            case 'PUT':
                // Einstellung aktualisieren
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!isset($data['setting_key']) || !isset($data['setting_value'])) {
                    throw new Exception('Fehlende Parameter');
                }

                $stmt = $db->prepare("
                    UPDATE system_settings 
                    SET setting_value = ?, updated_by = ? 
                    WHERE setting_key = ?
                ");
                $stmt->execute([
                    $data['setting_value'],
                    $authUserId,
                    $data['setting_key']
                ]);

                echo json_encode(['success' => true]);
                break;

            default:
                http_response_code(405);
                echo json_encode(['error' => 'Methode nicht erlaubt']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getAppearance($db)
{
    try {
    // Nur Appearance-Einstellungen für öffentlichen Zugriff
    $stmt = $db->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE category = 'appearance' OR setting_key = 'organization_name'
    ");
    $stmt->execute();
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    echo json_encode(['settings' => $settings]);
    
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Fehler beim Laden']);
    }
}

?>